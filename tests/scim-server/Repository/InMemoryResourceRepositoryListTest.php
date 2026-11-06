<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\SchemaUrn;

final class InMemoryResourceRepositoryListTest extends KernelTestCase
{
    private const string USER = SchemaUrn::User->value;
    private const string ENTERPRISE = SchemaUrn::EnterpriseUser->value;
    private const array USERS = [
        [
            'id' => 'u1',
            'meta' => ['created' => '2011-05-13T14:42:34+10:00'],
            'userName' => 'carol',
            'title' => 'Manager',
            'active' => true,
            'emails' => [['value' => 'z@example.com'], ['value' => 'b@example.com', 'primary' => true]],
            self::ENTERPRISE => ['employeeNumber' => 'E2'],
        ],
        [
            'id' => 'u2',
            'meta' => ['created' => '2011-05-13T05:00:00Z'],
            'userName' => 'Alice',
            'name' => ['givenName' => 'Alice'],
            'title' => 'Manager',
            'active' => 'yes',
            'emails' => [['value' => 'c@example.com', 'primary' => false], ['value' => 'a@example.com']],
        ],
        [
            'id' => 'u3',
            'meta' => ['created' => '2011-05-13T04:00:00Z'],
            'userName' => 'bob',
            'active' => false,
            self::ENTERPRISE => ['employeeNumber' => 'E1'],
        ],
        [
            'id' => 'u4',
            'userName' => 'dave',
            'title' => 'Director',
            'emails' => [['value' => 'd@example.com']],
        ],
    ];

    #[Test]
    #[TestDox('Returns complete documents in insertion order and ignores attributes and excludedAttributes')]
    public function returnsAllDocumentsInInsertionOrder(): void
    {
        $userName = new AttributePath(self::USER, 'userName');

        $result = $this->repository()->list(new ListQuery(attributes: [$userName], excludedAttributes: [$userName]));

        self::assertSame(self::USERS, $result->resources);
        self::assertSame(4, $result->totalResults);
    }

    /**
     * @return iterable<string, array{AttributePath, SortOrder, list<string>}>
     */
    public static function sortCases(): iterable
    {
        $userName = new AttributePath(self::USER, 'userName');
        $title = new AttributePath(self::USER, 'title');

        yield 'string, case ignored, ascending' => [$userName, SortOrder::Ascending, ['u2', 'u3', 'u1', 'u4']];
        yield 'equal values keep insertion order, no value last when ascending' => [$title, SortOrder::Ascending, ['u4', 'u1', 'u2', 'u3']];
        yield 'equal values keep insertion order, no value first when descending' => [$title, SortOrder::Descending, ['u3', 'u1', 'u2', 'u4']];
        yield 'multi-valued: primary value, else first value' => [new AttributePath(self::USER, 'emails', 'value'), SortOrder::Ascending, ['u1', 'u2', 'u4', 'u3']];
        yield 'extension attribute' => [new AttributePath(self::ENTERPRISE, 'employeeNumber'), SortOrder::Ascending, ['u3', 'u1', 'u2', 'u4']];
        yield 'dateTime, as instants' => [new AttributePath(self::USER, 'meta', 'created'), SortOrder::Ascending, ['u3', 'u1', 'u2', 'u4']];
        yield 'a value that does not fit the type has no value' => [new AttributePath(self::USER, 'active'), SortOrder::Ascending, ['u3', 'u1', 'u2', 'u4']];
        yield 'complex without value sub-attribute: insertion order' => [new AttributePath(self::USER, 'name'), SortOrder::Ascending, ['u1', 'u2', 'u3', 'u4']];
        yield 'undefined attribute: insertion order' => [new AttributePath(null, 'nickName2'), SortOrder::Descending, ['u1', 'u2', 'u3', 'u4']];
    }

    /**
     * @param list<string> $expectedIds
     */
    #[Test]
    #[DataProvider('sortCases')]
    #[TestDox('Sorts: $_dataName')]
    public function sorts(AttributePath $sortBy, SortOrder $sortOrder, array $expectedIds): void
    {
        self::assertSame([$expectedIds, 4], $this->ids($this->repository()->list(new ListQuery(sortBy: $sortBy, sortOrder: $sortOrder))));
    }

    /**
     * @return iterable<string, array{int<1, max>, int<0, max>, list<string>}>
     */
    public static function pages(): iterable
    {
        yield 'first page' => [1, 2, ['u1', 'u2']];
        yield 'last page, partial' => [4, 2, ['u4']];
        yield 'startIndex beyond the last match' => [5, 2, []];
        yield 'count 0' => [1, 0, []];
    }

    /**
     * @param int<1, max>  $startIndex
     * @param int<0, max>  $count
     * @param list<string> $expectedIds
     */
    #[Test]
    #[DataProvider('pages')]
    #[TestDox('Pages with the 1-based startIndex and count: $_dataName')]
    public function returnsOnePage(int $startIndex, int $count, array $expectedIds): void
    {
        self::assertSame([$expectedIds, 4], $this->ids($this->repository()->list(new ListQuery(startIndex: $startIndex, count: $count))));
    }

    #[Test]
    #[TestDox('Filters, then sorts, then pages; totalResults counts all matches')]
    public function filtersSortsThenPages(): void
    {
        $type = CoreResourceTypes::user();
        $query = new ListQuery(
            new FilterResolver()->resolve(new FilterParser()->parse('title pr'), $type),
            new AttributePath(self::USER, 'userName'),
            SortOrder::Descending,
            startIndex: 2,
            count: 1,
        );

        self::assertSame([['u1'], 3], $this->ids($this->repository()->list($query)));
    }

    #[Test]
    #[TestDox('A replaced document keeps its position, a created document comes last')]
    public function replacedDocumentKeepsPosition(): void
    {
        $repository = $this->repository();
        $repository->replace('u1', ['userName' => 'carol']);

        $created = (string) $repository->create(['userName' => 'erin'])['id'];

        self::assertSame([['u1', 'u2', 'u3', 'u4', $created], 5], $this->ids($repository->list(new ListQuery())));
    }

    private function repository(): InMemoryResourceRepository
    {
        return new InMemoryResourceRepository(CoreResourceTypes::user(), new MockClock(), self::USERS);
    }

    /**
     * @return array{list<mixed>, int}
     */
    private function ids(ListResult $result): array
    {
        return [array_column($result->resources, 'id'), $result->totalResults];
    }
}
