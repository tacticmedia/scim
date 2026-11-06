<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\Query\Parameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmQueryContext;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmQueryTranslator;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ElementMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\Plan\Node;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\UserAddress;

final class OrmElementCollectionTest extends KernelTestCase
{
    use CreatesOrmStore;
    private const string USER = User::class;
    private const string FORMATTED = "TRIM(CONCAT(COALESCE(r.firstName, ''), ' ', COALESCE(r.lastName, '')))";

    /**
     * @param \Closure(Plans): Node      $plan
     * @param list<array{string, mixed}> $parameters name, value
     */
    #[Test]
    #[DataProvider('conditions')]
    #[TestDox('Translates $_dataName')]
    public function translatesTheNode(\Closure $plan, string $dql, array $parameters): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));
        $context = $this->context();

        self::assertSame($dql, new OrmQueryTranslator(self::mappings())->condition($plan($plans), $context));
        self::assertSame($parameters, array_map(static fn (Parameter $parameter): array => [$parameter->getName(), $parameter->getValue()], $context->parameters()->getValues()));
    }

    /**
     * @return iterable<string, array{\Closure(Plans): Node, string, list<array{string, mixed}>}>
     */
    public static function conditions(): iterable
    {
        yield 'a condition on one element' => [static fn (Plans $p): Node => $p->exists('addresses', $p->eq('addresses.locality', 'Paris')), 'EXISTS (SELECT 1 FROM '.self::USER." o0 JOIN o0.addresses t0 WHERE o0 = r AND (t0.locality IS NOT NULL AND t0.locality <> '' AND LOWER(t0.locality) = LOWER(:scim_p0)))", [['scim_p0', 'Paris']]];
        yield 'any element' => [static fn (Plans $p): Node => $p->exists('addresses'), 'EXISTS (SELECT 1 FROM '.self::USER.' o0 JOIN o0.addresses t0 WHERE o0 = r)', []];
        yield 'a DQL expression, always guarded' => [static fn (Plans $p): Node => $p->compare('name.formatted', ComparisonOperator::StartsWith, 'Bar'), '('.self::FORMATTED.' IS NOT NULL AND '.self::FORMATTED." <> '' AND LOWER(".self::FORMATTED.") LIKE LOWER(:scim_p0) ESCAPE '!')", [['scim_p0', 'Bar%']]];
        yield 'pr on a DQL expression' => [static fn (Plans $p): Node => $p->present('name.formatted'), '('.self::FORMATTED.' IS NOT NULL AND '.self::FORMATTED." <> '')", []];
    }

    #[Test]
    #[TestDox('An element sub-attribute sorts through a join of the primary element, else of the element with the lowest id')]
    public function joinsTheElementThatSorts(): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));
        $context = $this->context();

        $items = new OrmQueryTranslator(self::mappings())->orderBy([$plans->at('addresses'), $plans->at('addresses.locality')], SortOrder::Descending, $context);

        self::assertSame([
            ["CASE WHEN (s0.locality IS NOT NULL AND s0.locality <> '') THEN 0 ELSE 1 END", 'DESC'],
            ["CASE WHEN (s0.locality IS NOT NULL AND s0.locality <> '') THEN LOWER(s0.locality) ELSE '' END", 'DESC'],
            ['r.id', 'ASC'],
        ], $items);
        self::assertSame([['r.addresses', 's0', 'NOT EXISTS (SELECT 1 FROM '.self::USER.' o0 JOIN o0.addresses t0 WHERE o0 = r AND (CASE WHEN t0.primary = true THEN 0 ELSE 1 END < CASE WHEN s0.primary = true THEN 0 ELSE 1 END OR (CASE WHEN t0.primary = true THEN 0 ELSE 1 END = CASE WHEN s0.primary = true THEN 0 ELSE 1 END AND t0.id < s0.id)))']], $context->joins());
    }

    #[Test]
    #[TestDox('A DQL expression sorts by itself')]
    public function sortsByTheExpression(): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));

        self::assertSame([
            ['CASE WHEN ('.self::FORMATTED.' IS NOT NULL AND '.self::FORMATTED." <> '') THEN 0 ELSE 1 END", 'ASC'],
            ['CASE WHEN ('.self::FORMATTED.' IS NOT NULL AND '.self::FORMATTED." <> '') THEN LOWER(".self::FORMATTED.") ELSE '' END", 'ASC'],
            ['r.id', 'ASC'],
        ], new OrmQueryTranslator(self::mappings())->orderBy([$plans->at('name.formatted')], SortOrder::Ascending, $this->context()));
    }

    #[Test]
    #[TestDox('An element sub-attribute or a DQL expression as sortBy has no keyset, so the server pages by offset')]
    public function sortsWithoutKeyset(): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));

        self::assertNull(self::store()->queryAfter($plans->mapping(), new ObjectQuery(null, [$plans->at('addresses'), $plans->at('addresses.locality')], SortOrder::Ascending, 1, 1), null));
        self::assertNull(self::store()->queryAfter($plans->mapping(), new ObjectQuery(null, [$plans->at('name.formatted')], SortOrder::Ascending, 1, 1), null));
    }

    #[Test]
    #[TestDox('First use fails for an element collection that is not a to-many association to the element class')]
    public function elementCollectionNeedsAToManyAssociation(): void
    {
        self::createSchema();
        $users = self::mappings()->get('User');

        $this->expectExceptionObject(new MappingException([self::USER.'::$manager: "addresses" lists '.UserAddress::class.' objects, but $manager is not a to-many Doctrine association to '.UserAddress::class.'. Map $manager with #[ORM\OneToMany] or #[ORM\ManyToMany] to '.UserAddress::class.'.']));

        self::store()->find($this->swapped($users, $this->at($users, 'addresses'), member: 'manager'), 'x');
    }

    #[Test]
    #[TestDox('First use fails for an element collection without an order by the element id, and for an element member without a column')]
    public function elementCollectionNeedsAnOrderAndColumns(): void
    {
        $association = self::createSchema()->getClassMetadata(self::USER)->getAssociationMapping('addresses');
        self::assertInstanceOf(OneToManyAssociationMapping::class, $association);
        $association->orderBy = [];
        $users = self::mappings()->get('User');
        $addresses = $this->at($users, 'addresses');
        $element = $addresses->element ?? throw new \LogicException('No element.');
        $broken = new ElementMapping($element->class, array_map(fn (AttributeMapping $attribute): AttributeMapping => 'locality' === $attribute->member ? $this->copy($attribute, member: 'town') : $attribute, $element->attributes), $element->constructorParameters, $element->ownerParameter);

        $this->expectExceptionObject(new MappingException([
            self::USER."::\$addresses: \"addresses\" needs one element order for reads, ETags and sortBy. Add #[ORM\\OrderBy(['id' => 'ASC'])] to \$addresses.",
            UserAddress::class.'::$town: "addresses.locality" needs a Doctrine field, but $town is not mapped by Doctrine, so writes to it are lost. Add #[ORM\Column] to $town.',
        ]));

        self::store()->find($this->swapped($users, $addresses, element: $broken), 'x');
    }

    #[Test]
    #[TestDox('First use fails for a DQL expression that Doctrine cannot parse')]
    public function expressionMustParse(): void
    {
        self::createSchema();
        $users = self::mappings()->get('User');

        try {
            self::store()->find($this->swapped($users, $this->at($users, 'getFormattedName'), dqlExpression: '{alias}.fullName'), 'x');
            self::fail('No MappingException.');
        } catch (MappingException $mappingException) {
            self::assertCount(1, $mappingException->errors);
            self::assertStringStartsWith(self::USER.'::getFormattedName(): "name.formatted" has a #[ScimDqlExpression] that Doctrine cannot use: ', $mappingException->errors[0]);
            self::assertStringContainsString('fullName', $mappingException->errors[0]);
            self::assertStringEndsWith("Write a DQL scalar expression with {alias} before each field, for example CONCAT({alias}.firstName, ' ', {alias}.lastName).", $mappingException->errors[0]);
        }
    }

    private function context(): OrmQueryContext
    {
        return new OrmQueryContext(self::getContainer()->get('doctrine.orm.entity_manager'), self::USER);
    }

    private function at(ResourceMapping $mapping, string $member): AttributeMapping
    {
        $found = array_find($mapping->attributes, static fn (AttributeMapping $attribute): bool => $attribute->member === $member);
        self::assertInstanceOf(AttributeMapping::class, $found);

        return $found;
    }

    private function swapped(ResourceMapping $mapping, AttributeMapping $old, ?string $member = null, ?ElementMapping $element = null, ?string $dqlExpression = null): ResourceMapping
    {
        $new = $this->copy($old, $member, $element, $dqlExpression);

        return $mapping->withAttributes(array_map(static fn (AttributeMapping $attribute): AttributeMapping => $attribute === $old ? $new : $attribute, $mapping->attributes));
    }

    private function copy(AttributeMapping $old, ?string $member = null, ?ElementMapping $element = null, ?string $dqlExpression = null): AttributeMapping
    {
        return new AttributeMapping($old->path, $old->declaredPath, $old->definition, $old->kind, $member ?? $old->member, $old->method, $old->phpType, $old->nullable, $old->writable, $old->required, $old->adder, $old->remover, $old->converter, $old->type, $old->primary, $old->targetType, $old->default, $element ?? $old->element, $dqlExpression ?? $old->dqlExpression);
    }
}
