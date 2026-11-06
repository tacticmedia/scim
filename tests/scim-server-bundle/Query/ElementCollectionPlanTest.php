<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\NativeConverter;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\QueryPlanner;
use TacticMedia\ScimServerBundle\Tests\Query\Fixtures\Pager;

final class ElementCollectionPlanTest extends KernelTestCase
{
    use PlansQueries;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filters(): iterable
    {
        yield 'eq on a sub-attribute' => ['addresses.locality eq "Paris"', 'exists($addresses, $locality eq "Paris")'];
        yield 'co on a sub-attribute' => ['addresses.locality co "ar"', 'exists($addresses, $locality co "ar")'];
        yield 'pr on a sub-attribute' => ['addresses.locality pr', 'exists($addresses, $locality pr)'];
        yield 'eq on type and primary' => ['addresses.type eq "work" or addresses.primary eq true', 'or(exists($addresses, $type eq "work"), exists($addresses, $primary eq true))'];
        yield 'one element satisfies the value path' => ['addresses[type eq "work" and locality sw "P"]', 'exists($addresses, and($type eq "work", $locality sw "P"))'];
        yield 'not inside the value path' => ['addresses[not (type eq "work")]', 'exists($addresses, not($type eq "work"))'];
        yield 'ne: no element has a value, or one has another' => ['addresses.locality ne "Paris"', 'or(not(exists($addresses, $locality pr)), exists($addresses, and($locality pr, not($locality eq "Paris"))))'];
        yield 'pr on the attribute' => ['addresses pr', 'exists($addresses)'];
        yield 'a literal that converts to null' => ['addresses.locality eq ""', 'false'];
        yield 'a DQL expression' => ['name.formatted sw "Bar"', 'getFormattedName() sw "Bar"'];
        yield 'pr on a DQL expression' => ['name.formatted pr', 'getFormattedName() pr'];
        yield 'a DQL expression in the element of its attribute' => ['name[formatted eq "Babs Jensen"]', 'and(or($firstName pr, $lastName pr, getFormattedName() pr), getFormattedName() eq "Babs Jensen")'];
    }

    #[Test]
    #[DataProvider('filters')]
    #[TestDox('$_dataName: $filter')]
    public function plansTheFilter(string $filter, string $plan): void
    {
        self::assertSame($plan, PlanText::of($this->plan(new ListQuery($this->resolved($filter, 'User')), 'User')));
    }

    #[Test]
    #[TestDox('An element that can render no sub-attribute is guarded by the presence of one')]
    public function guardsElementsThatCanBeEmpty(): void
    {
        self::assertSame('exists($ims, and(or($value pr, $primary pr), not($value eq "x")))', PlanText::of($this->pagerPlan('ims[not (value eq "x")]')));
        self::assertSame('exists($ims, and(or($value pr, $primary pr), $value pr))', PlanText::of($this->pagerPlan('ims pr')));
    }

    #[Test]
    #[TestDox('An element sub-attribute that the element class does not map is not supported')]
    public function rejectsAnUnmappedSubAttribute(): void
    {
        $registry = new MappingRegistry(new AttributeLoader(), ['Pager' => Pager::class]);
        $unpublished = new Comparison(new AttributePath('urn:ietf:params:scim:schemas:core:2.0:User', 'ims', 'type'), ComparisonOperator::Equal, 'aim');

        $this->expectExceptionObject(ScimException::invalidFilter("The attribute 'urn:ietf:params:scim:schemas:core:2.0:User:ims.type' is not supported."));

        $this->planner($registry)->plan(new ListQuery($unpublished), $registry->get('Pager'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function sorts(): iterable
    {
        yield 'an element sub-attribute: the collection, then the member' => ['addresses.locality', ['$addresses', '$locality']];
        yield 'a DQL expression' => ['name.formatted', ['getFormattedName()']];
    }

    /**
     * @param list<string> $sortBy
     */
    #[Test]
    #[DataProvider('sorts')]
    #[TestDox('sortBy $path')]
    public function sortsBy(string $path, array $sortBy): void
    {
        $query = $this->plan(new ListQuery(sortBy: $this->path($path, 'User')), 'User');

        self::assertInstanceOf(ObjectQuery::class, $query);
        self::assertSame($sortBy, array_map(static fn (AttributeMapping $member): string => $member->label(), $query->sortBy));
    }

    #[Test]
    #[TestDox('The response loads the element collection unless it is excluded')]
    public function loadsTheElementCollection(): void
    {
        $all = $this->plan(new ListQuery(), 'User');
        $excluded = $this->plan(new ListQuery(excludedAttributes: [$this->path('addresses', 'User')]), 'User');

        self::assertInstanceOf(ObjectQuery::class, $all);
        self::assertInstanceOf(ObjectQuery::class, $excluded);
        self::assertSame([['$manager', '$groups', '$addresses'], ['$manager', '$groups']], [$this->labels($all->load), $this->labels($excluded->load)]);
    }

    private function pagerPlan(string $filter): ObjectQuery|false
    {
        $registry = new MappingRegistry(new AttributeLoader(), ['Pager' => Pager::class]);
        $mapping = $registry->get('Pager');
        $resolved = new FilterResolver()->resolve(new FilterParser()->parse($filter), $mapping->resourceType);

        return $this->planner($registry)->plan(new ListQuery($resolved), $mapping);
    }

    private function planner(MappingRegistry $registry): QueryPlanner
    {
        return new QueryPlanner($registry, new ServiceLocator([
            NativeConverter::class => static fn (): NativeConverter => new NativeConverter(),
            IdConverter::class => static fn (): IdConverter => new IdConverter(),
        ]));
    }

    /**
     * @param list<AttributeMapping> $mappings
     *
     * @return list<string>
     */
    private function labels(array $mappings): array
    {
        return array_map(static fn (AttributeMapping $mapping): string => $mapping->label(), $mappings);
    }
}
