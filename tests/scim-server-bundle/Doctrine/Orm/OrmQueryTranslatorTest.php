<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use Doctrine\ORM\Query\Parameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmQueryContext;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmQueryTranslator;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Query\Plan\Compare;
use TacticMedia\ScimServerBundle\Query\Plan\Exists;
use TacticMedia\ScimServerBundle\Query\Plan\IsPresent;
use TacticMedia\ScimServerBundle\Query\Plan\Node;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

final class OrmQueryTranslatorTest extends KernelTestCase
{
    use CreatesOrmStore;
    private const string USER = User::class;
    private const string GROUP = Group::class;

    /**
     * @param \Closure(Plans): Node                                   $plan
     * @param \Closure(Directory): list<array{string, mixed, string}> $parameters name, value, Doctrine type
     */
    #[Test]
    #[DataProvider('conditions')]
    #[TestDox('Translates $_dataName')]
    public function translatesTheNode(string $resource, \Closure $plan, string $dql, \Closure $parameters): void
    {
        $directory = Directory::seed(self::createSchema());
        $plans = new Plans(self::mappings(), $resource, $directory);
        $context = $this->context($plans);

        self::assertSame($dql, new OrmQueryTranslator(self::mappings())->condition($plan($plans), $context));
        self::assertEquals($parameters($directory), array_map(static fn (Parameter $parameter): array => [$parameter->getName(), $parameter->getValue(), $parameter->getType()], $context->parameters()->getValues()));
    }

    /**
     * @return iterable<string, array{string, \Closure(Plans): Node, string, \Closure(Directory): list<array{string, mixed, string}>}>
     */
    public static function conditions(): iterable
    {
        $none = static fn (): array => [];

        yield 'a case-insensitive string comparison on a column without null' => ['User', static fn (Plans $p): Node => $p->eq('userName', 'x'), "(r.userName <> '' AND LOWER(r.userName) = LOWER(:scim_p0))", static fn (): array => [['scim_p0', 'x', 'string']]];
        yield 'a case-exact string comparison on a nullable column' => ['User', static fn (Plans $p): Node => $p->eq('externalId', 'x'), "(r.externalId IS NOT NULL AND r.externalId <> '' AND r.externalId = :scim_p0)", static fn (): array => [['scim_p0', 'x', 'string']]];
        yield 'an id comparison with the identifier type' => ['User', static fn (Plans $p): Node => $p->idOf('User', 'bjensen'), 'r.id = :scim_p0', static fn (Directory $d): array => [['scim_p0', $d->id('bjensen'), 'uuid']]];
        yield 'a dateTime comparison' => ['User', static fn (Plans $p): Node => $p->compare('meta.lastModified', ComparisonOperator::GreaterThanOrEqual, new \DateTimeImmutable('2026-01-01T00:00:00Z')), 'r.updatedAt >= :scim_p0', static fn (): array => [['scim_p0', new \DateTimeImmutable('2026-01-01T00:00:00Z'), 'datetime_immutable']]];
        yield 'lt and le' => ['User', static fn (Plans $p): Node => $p->all($p->compare('meta.lastModified', ComparisonOperator::LessThan, 1), $p->compare('meta.lastModified', ComparisonOperator::LessThanOrEqual, 2)), '(r.updatedAt < :scim_p0 AND r.updatedAt <= :scim_p1)', static fn (): array => [['scim_p0', 1, 'datetime_immutable'], ['scim_p1', 2, 'datetime_immutable']]];
        yield 'co with escaped !, % and _' => ['User', static fn (Plans $p): Node => $p->compare('displayName', ComparisonOperator::Contains, '5!%_'), "(r.displayName IS NOT NULL AND r.displayName <> '' AND LOWER(r.displayName) LIKE LOWER(:scim_p0) ESCAPE '!')", static fn (): array => [['scim_p0', '%5!!!%!_%', 'string']]];
        yield 'sw' => ['User', static fn (Plans $p): Node => $p->compare('userName', ComparisonOperator::StartsWith, 'b'), "(r.userName <> '' AND LOWER(r.userName) LIKE LOWER(:scim_p0) ESCAPE '!')", static fn (): array => [['scim_p0', 'b%', 'string']]];
        yield 'ew on a case-exact column' => ['User', static fn (Plans $p): Node => $p->compare('externalId', ComparisonOperator::EndsWith, 'x'), "(r.externalId IS NOT NULL AND r.externalId <> '' AND r.externalId LIKE :scim_p0 ESCAPE '!')", static fn (): array => [['scim_p0', '%x', 'string']]];
        yield 'pr on a string' => ['User', static fn (Plans $p): Node => $p->present('displayName'), "(r.displayName IS NOT NULL AND r.displayName <> '')", $none];
        yield 'pr on a dateTime' => ['User', static fn (Plans $p): Node => $p->present('meta.lastModified'), 'r.updatedAt IS NOT NULL', $none];
        yield 'not' => ['User', static fn (Plans $p): Node => $p->not($p->eq('externalId', 'x')), "NOT ((r.externalId IS NOT NULL AND r.externalId <> '' AND r.externalId = :scim_p0))", static fn (): array => [['scim_p0', 'x', 'string']]];
        yield 'and with a nested or' => ['User', static fn (Plans $p): Node => $p->all($p->present('displayName'), $p->any($p->eq('active', true), $p->eq('active', false))), "((r.displayName IS NOT NULL AND r.displayName <> '') AND (r.enabled = :scim_p0 OR r.enabled = :scim_p1))", static fn (): array => [['scim_p0', true, 'boolean'], ['scim_p1', false, 'boolean']]];
        yield 'a reference with a condition' => ['User', static fn (Plans $p): Node => $p->exists('manager', $p->idOf('User', 'bjensen')), 'EXISTS (SELECT 1 FROM '.self::USER.' t0 WHERE t0 = r.manager AND t0.deletedAt IS NULL AND t0.id = :scim_p0)', static fn (Directory $d): array => [['scim_p0', $d->id('bjensen'), 'uuid']]];
        yield 'an inverse reference collection' => ['User', static fn (Plans $p): Node => $p->exists('groups'), 'EXISTS (SELECT 1 FROM '.self::USER.' o0 JOIN o0.groups t0 WHERE o0 = r)', $none];
        yield 'two reference collections with conditions' => ['Group', static fn (Plans $p): Node => $p->any($p->exists('members', $p->idOf('User', 'jsmith'), 'User'), $p->exists('members', $p->idOf('Group', 'Sales'), 'Group')), '(EXISTS (SELECT 1 FROM '.self::GROUP.' o0 JOIN o0.users t0 WHERE o0 = r AND t0.deletedAt IS NULL AND t0.id = :scim_p0) OR EXISTS (SELECT 1 FROM '.self::GROUP.' o1 JOIN o1.subgroups t1 WHERE o1 = r AND t1.id = :scim_p1))', static fn (Directory $d): array => [['scim_p0', $d->id('jsmith'), 'uuid'], ['scim_p1', $d->id('Sales'), 'uuid']]];
    }

    /**
     * @param \Closure(Plans): Node $plan
     */
    #[Test]
    #[DataProvider('untranslatable')]
    #[TestDox('Rejects $_dataName with 400 invalidFilter')]
    public function rejectsTheNode(\Closure $plan, string $message): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));

        try {
            new OrmQueryTranslator(self::mappings())->condition($plan($plans), $this->context($plans));
            self::fail('No exception.');
        } catch (ScimException $scimException) {
            self::assertSame([400, ScimErrorType::InvalidFilter, $message], [$scimException->status, $scimException->scimType, $scimException->detail]);
        }
    }

    /**
     * @return iterable<string, array{\Closure(Plans): Node, string}>
     */
    public static function untranslatable(): iterable
    {
        $node = new class implements Node {};

        yield 'an unknown node' => [static fn (): Node => $node, \sprintf('The plan node %s is not supported by the Doctrine ORM store.', $node::class)];
        yield 'a comparison on a method' => [static fn (Plans $p): Node => new Compare(self::computed($p), ComparisonOperator::Equal, 'x'), "The attribute 'urn:ietf:params:scim:schemas:core:2.0:User:displayName' cannot be used in a filter."];
        yield 'pr on a method' => [static fn (Plans $p): Node => new IsPresent(self::computed($p)), "The attribute 'urn:ietf:params:scim:schemas:core:2.0:User:displayName' cannot be used in a filter."];
        yield 'a relation that is not an association' => [static fn (Plans $p): Node => new Exists($p->at('displayName')), "The attribute 'urn:ietf:params:scim:schemas:core:2.0:User:displayName' cannot be used in a filter."];
        yield 'ne' => [static fn (Plans $p): Node => $p->compare('userName', ComparisonOperator::NotEqual, 'x'), "The operator 'ne' is not supported in a plan. Use Negate."];
        yield 'co with a value that is not a string' => [static fn (Plans $p): Node => $p->compare('userName', ComparisonOperator::Contains, 5), "The operator 'co' needs a string value for 'urn:ietf:params:scim:schemas:core:2.0:User:userName'."];
    }

    /**
     * @param \Closure(Plans): list<AttributeMapping> $sortBy
     * @param list<array{string, string}>             $expected
     */
    #[Test]
    #[DataProvider('sortKeys')]
    #[TestDox('Orders by $_dataName')]
    public function ordersBy(\Closure $sortBy, SortOrder $order, array $expected): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));

        self::assertSame($expected, new OrmQueryTranslator(self::mappings())->orderBy($sortBy($plans), $order, $this->context($plans)));
    }

    /**
     * @return iterable<string, array{\Closure(Plans): list<AttributeMapping>, SortOrder, list<array{string, string}>}>
     */
    public static function sortKeys(): iterable
    {
        yield 'the id without sortBy' => [static fn (): array => [], SortOrder::Descending, [['r.id', 'ASC']]];
        yield 'a case-insensitive string, ascending' => [static fn (Plans $p): array => [$p->at('userName')], SortOrder::Ascending, [["CASE WHEN (r.userName IS NOT NULL AND r.userName <> '') THEN 0 ELSE 1 END", 'ASC'], ["CASE WHEN (r.userName IS NOT NULL AND r.userName <> '') THEN LOWER(r.userName) ELSE '' END", 'ASC'], ['r.id', 'ASC']]];
        yield 'a case-exact string, descending' => [static fn (Plans $p): array => [$p->at('externalId')], SortOrder::Descending, [["CASE WHEN (r.externalId IS NOT NULL AND r.externalId <> '') THEN 0 ELSE 1 END", 'DESC'], ["CASE WHEN (r.externalId IS NOT NULL AND r.externalId <> '') THEN r.externalId ELSE '' END", 'DESC'], ['r.id', 'ASC']]];
        yield 'a dateTime' => [static fn (Plans $p): array => [$p->at('meta.lastModified')], SortOrder::Ascending, [['CASE WHEN r.updatedAt IS NOT NULL THEN 0 ELSE 1 END', 'ASC'], ['r.updatedAt', 'ASC'], ['r.id', 'ASC']]];
        yield 'the first present of two strings' => [static fn (Plans $p): array => [$p->at('phoneNumbers.value', 'work'), $p->at('phoneNumbers.value', 'mobile')], SortOrder::Ascending, [
            ["CASE WHEN (r.phone IS NOT NULL AND r.phone <> '') OR (r.mobile IS NOT NULL AND r.mobile <> '') THEN 0 ELSE 1 END", 'ASC'],
            ["CASE WHEN (r.phone IS NOT NULL AND r.phone <> '') THEN LOWER(r.phone) WHEN (r.mobile IS NOT NULL AND r.mobile <> '') THEN LOWER(r.mobile) ELSE '' END", 'ASC'],
            ['r.id', 'ASC'],
        ]];
        yield 'the first present of two dateTimes' => [static fn (Plans $p): array => [$p->at('meta.lastModified'), $p->at('meta.created')], SortOrder::Descending, [
            ['CASE WHEN r.updatedAt IS NOT NULL OR r.createdAt IS NOT NULL THEN 0 ELSE 1 END', 'DESC'],
            ['CASE WHEN r.updatedAt IS NOT NULL THEN r.updatedAt ELSE r.createdAt END', 'DESC'],
            ['r.id', 'ASC'],
        ]];
    }

    #[Test]
    #[TestDox('sortBy on a method is 400 invalidValue')]
    public function rejectsAMethodSortKey(): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));

        $this->expectExceptionObject(ScimException::invalidValue("sortBy 'urn:ietf:params:scim:schemas:core:2.0:User:displayName' is not supported."));

        new OrmQueryTranslator(self::mappings())->orderBy([self::computed($plans)], SortOrder::Ascending, $this->context($plans));
    }

    #[Test]
    #[TestDox('An unknown alias is a logic error')]
    public function rejectsAnUnknownAlias(): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));

        $this->expectExceptionObject(new \LogicException('The alias "t0" is not defined.'));

        $this->context($plans)->metadata('t0');
    }

    private function context(Plans $plans): OrmQueryContext
    {
        return new OrmQueryContext(self::getContainer()->get('doctrine.orm.entity_manager'), $plans->mapping()->class);
    }

    /**
     * displayName mapped by the method getDisplayName().
     */
    private static function computed(Plans $plans): AttributeMapping
    {
        $field = $plans->at('displayName');

        return new AttributeMapping($field->path, $field->declaredPath, $field->definition, MappingKind::Computed, 'getDisplayName', true, 'string', true, false, false);
    }
}
