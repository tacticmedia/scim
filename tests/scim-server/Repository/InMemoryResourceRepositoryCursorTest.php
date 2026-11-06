<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;

final class InMemoryResourceRepositoryCursorTest extends KernelTestCase
{
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    #[Test]
    #[TestDox('listAfter() without a position returns the first page, all matches and the position of its last resource')]
    public function returnsFirstPage(): void
    {
        $page = $this->repository()->listAfter(new ListQuery(count: 2), null);

        self::assertSame([['u1', 'u2'], 5, [null, 1]], [array_column($page->resources, 'id'), $page->totalResults, $page->next]);
    }

    /**
     * @return iterable<string, array{AttributePath|null, SortOrder}>
     */
    public static function orders(): iterable
    {
        yield 'insertion order' => [null, SortOrder::Ascending];
        yield 'title ascending, case-insensitive, absent last' => [self::path('title'), SortOrder::Ascending];
        yield 'title descending, absent first' => [self::path('title'), SortOrder::Descending];
        yield 'an attribute the documents do not have' => [self::path('nickName'), SortOrder::Descending];
    }

    #[Test]
    #[DataProvider('orders')]
    #[TestDox('Following next gives the order of list(): $_dataName')]
    public function followsListOrder(?AttributePath $sortBy, SortOrder $order): void
    {
        $repository = $this->repository();
        $byIndex = array_column($repository->list(new ListQuery(sortBy: $sortBy, sortOrder: $order))->resources, 'id');

        self::assertSame($byIndex, array_merge(...$this->traverse($repository, new ListQuery(sortBy: $sortBy, sortOrder: $order, count: 2))));
    }

    #[Test]
    #[TestDox('The position holds values: a deleted last resource still marks where the next page starts')]
    public function survivesDeletedPosition(): void
    {
        $repository = $this->repository();
        $query = new ListQuery(sortBy: self::path('title'), count: 2);
        $first = $repository->listAfter($query, null);

        $repository->delete('u4');
        $repository->create(['schemas' => [self::USER], 'userName' => 'early', 'title' => 'a']);

        self::assertSame([['u2', 'u4'], ['u1', 'u3']], [array_column($first->resources, 'id'), array_column($repository->listAfter($query, $first->next)->resources, 'id')]);
    }

    #[Test]
    #[TestDox('listAfter() filters, and count 0 returns no resource and no position')]
    public function filtersAndCountsZero(): void
    {
        $repository = $this->repository();
        $filter = new FilterResolver()->resolve(new FilterParser()->parse('title pr'), CoreResourceTypes::user());

        $page = $repository->listAfter(new ListQuery($filter, count: 0), null);

        self::assertSame([[], 3, null], [$page->resources, $page->totalResults, $page->next]);
    }

    /**
     * @return iterable<string, array{list<int|float|string|bool|null>}>
     */
    public static function invalidPositions(): iterable
    {
        yield 'one value' => [[1]];
        yield 'a sequence that is not an integer' => [[null, '1']];
    }

    /**
     * @param list<int|float|string|bool|null> $after
     */
    #[Test]
    #[DataProvider('invalidPositions')]
    #[TestDox('A position of another shape answers 400 invalidCursor: $_dataName')]
    public function rejectsInvalidPosition(array $after): void
    {
        $this->expectExceptionObject(ScimException::invalidCursor());

        $this->repository()->listAfter(new ListQuery(), $after);
    }

    /**
     * @return list<list<mixed>> the ids of each page
     */
    private function traverse(InMemoryResourceRepository $repository, ListQuery $query): array
    {
        $pages = [];
        $after = null;

        do {
            $page = $repository->listAfter($query, $after);
            $pages[] = array_column($page->resources, 'id');
            $after = $page->next;
        } while (null !== $after && \count($pages) < 10);

        return $pages;
    }

    private function repository(): InMemoryResourceRepository
    {
        return new InMemoryResourceRepository(CoreResourceTypes::user(), new MockClock(), [
            ['schemas' => [self::USER], 'id' => 'u1', 'userName' => 'carol', 'title' => 'Manager'],
            ['schemas' => [self::USER], 'id' => 'u2', 'userName' => 'alice', 'title' => 'director'],
            ['schemas' => [self::USER], 'id' => 'u3', 'userName' => 'bob'],
            ['schemas' => [self::USER], 'id' => 'u4', 'userName' => 'dave', 'title' => 'Engineer'],
            ['schemas' => [self::USER], 'id' => 'u5', 'userName' => 'erin'],
        ]);
    }

    private static function path(string $attribute): AttributePath
    {
        return AttributePath::parse(self::USER.':'.$attribute) ?? throw new \LogicException($attribute);
    }
}
