<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\Plan\AnyOf;
use TacticMedia\ScimServerBundle\Query\Plan\Compare;
use TacticMedia\ScimServerBundle\Query\Plan\Exists;
use TacticMedia\ScimServerBundle\Query\Plan\Node;

final class QueryPlannerTest extends KernelTestCase
{
    use PlansQueries;
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const string ID = '0193a8e2-5c6f-7b4e-9f2a-3c1d2e4f5a6b';

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function sortable(): iterable
    {
        yield 'Field' => ['User', 'userName', '$userName'];
        yield 'complex sub-attribute' => ['User', 'name.givenName', '$firstName'];
        yield 'Slot' => ['User', 'emails.value', '$email'];
        yield 'Slots, the primary group first' => ['User', 'phoneNumbers.value', '$phone, $mobile'];
        yield 'Slots, the primary group declared last' => ['Contact', 'addresses.locality', '$homeLocality, $workLocality'];
        yield 'string id' => ['Contact', 'id', '$id'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsortable(): iterable
    {
        yield 'Reference' => ['User', 'manager'];
        yield 'ReferenceCollection' => ['User', 'groups.value'];
        yield 'Computed' => ['Device', 'label'];
        yield 'writeOnly' => ['Contact', 'password'];
        yield 'slot type' => ['User', 'emails.type'];
        yield 'unmapped' => ['User', 'meta.location'];
        yield 'uuid id' => ['User', 'id'];
        yield 'int id' => ['Device', 'id'];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>, list<string>}>
     */
    public static function loads(): iterable
    {
        yield 'all by default' => ['User', [], [], ['$manager', '$groups', '$addresses']];
        yield 'excluded' => ['Group', [], ['members'], []];
        yield 'excluded sub-attribute' => ['Group', [], ['members.value'], ['$users', '$subgroups']];
        yield 'not selected' => ['Group', ['displayName'], [], []];
        yield 'selected sub-attribute' => ['Group', ['members.value'], [], ['$users', '$subgroups']];
        yield 'selected' => ['User', ['manager'], [], ['$manager']];
    }

    #[Test]
    #[TestDox('Without a filter the plan has no condition and keeps the page and the order')]
    public function noFilter(): void
    {
        $query = $this->plan(new ListQuery(sortOrder: SortOrder::Descending, startIndex: 3, count: 7), 'User');

        self::assertInstanceOf(ObjectQuery::class, $query);
        self::assertSame([null, [], SortOrder::Descending, 3, 7], [$query->filter, $query->sortBy, $query->sortOrder, $query->startIndex, $query->count]);
    }

    #[Test]
    #[DataProvider('sortable')]
    #[TestDox('sortBy $sortBy on $name sorts by $expected')]
    public function sorts(string $name, string $sortBy, string $expected): void
    {
        $query = $this->plan(new ListQuery(sortBy: $this->path($sortBy, $name)), $name);

        self::assertInstanceOf(ObjectQuery::class, $query);
        self::assertSame($expected, implode(', ', array_map(static fn (AttributeMapping $member): string => $member->label(), $query->sortBy)));

        foreach ($query->sortBy as $member) {
            self::assertContains($member, $this->mapping($name)->attributes);
        }
    }

    #[Test]
    #[DataProvider('unsortable')]
    #[TestDox('sortBy $sortBy on $name fails')]
    public function rejectsSortBy(string $name, string $sortBy): void
    {
        $path = $this->path($sortBy, $name);

        try {
            $this->plan(new ListQuery(sortBy: $path), $name);
        } catch (ScimException $scimException) {
            self::assertSame([400, ScimErrorType::InvalidValue, \sprintf("sortBy '%s' is not supported.", $path)], [$scimException->status, $scimException->scimType, $scimException->detail]);

            return;
        }

        self::fail('No ScimException.');
    }

    #[Test]
    #[TestDox('An unsortable sortBy fails also when the filter is constant false')]
    public function rejectsSortByBeforeFolding(): void
    {
        $this->expectException(ScimException::class);

        $this->plan(new ListQuery($this->resolved('id eq "x"', 'User'), $this->path('manager', 'User')), 'User');
    }

    /**
     * @param list<string> $attributes
     * @param list<string> $excluded
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('loads')]
    #[TestDox('$name loads $expected')]
    public function loadsRelations(string $name, array $attributes, array $excluded, array $expected): void
    {
        $paths = fn (array $paths): array => array_map(fn (string $path): AttributePath => $this->path($path, $name), $paths);
        $query = $this->plan(new ListQuery(attributes: $paths($attributes), excludedAttributes: $paths($excluded)), $name);

        self::assertInstanceOf(ObjectQuery::class, $query);
        self::assertSame($expected, array_map(static fn (AttributeMapping $relation): string => $relation->label(), $query->load));
    }

    #[Test]
    #[TestDox('Operands are the mappings of the queried class and, inside Exists, of the target class')]
    public function operands(): void
    {
        $query = $this->plan(new ListQuery($this->resolved('members eq "'.self::ID.'"', 'Group')), 'Group');
        $group = $this->mapping('Group');
        $filter = $query instanceof ObjectQuery ? $query->filter : null;
        self::assertInstanceOf(AnyOf::class, $filter);

        self::assertSame($group->attributesAt(new AttributePath('urn:ietf:params:scim:schemas:core:2.0:Group', 'members')), array_map(static fn (Node $node): ?AttributeMapping => $node instanceof Exists ? $node->relation : null, $filter->nodes));
        self::assertSame([$this->mapping('User')->id, $group->id], array_map(static fn (Node $node): ?AttributeMapping => $node instanceof Exists && $node->condition instanceof Compare ? $node->condition->operand : null, $filter->nodes));
    }

    /**
     * @return iterable<string, array{string, Filter, string}>
     */
    public static function handBuilt(): iterable
    {
        yield 'gt null matches nothing' => ['User', new Comparison(new AttributePath(self::USER, 'displayName'), ComparisonOperator::GreaterThan, null), 'false'];
        yield 'comparison on a complex attribute matches nothing' => ['User', new Comparison(new AttributePath(self::USER, 'name'), ComparisonOperator::Equal, 'x'), 'false'];
        yield 'converter failure matches nothing' => ['Device', new Comparison(new AttributePath('urn:acme:scim:schemas:extension:audit:1.0:Device', 'tag'), ComparisonOperator::Equal, 5), 'false'];
    }

    #[Test]
    #[DataProvider('handBuilt')]
    #[TestDox('A tree that the library does not build: $expected')]
    public function trees(string $name, Filter $filter, string $expected): void
    {
        self::assertSame($expected, PlanText::of($this->plan(new ListQuery($filter), $name)));
    }
}
