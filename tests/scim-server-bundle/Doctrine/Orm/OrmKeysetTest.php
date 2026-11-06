<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmKeyset;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Object\CursorObjectPage;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures\Shift;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

/**
 * OrmObjectStore::queryAfter(), one object per page, each position through JSON as in a cursor. Environment keyset.
 */
final class OrmKeysetTest extends KernelTestCase
{
    use CreatesOrmStore;

    /**
     * @param list<string> $expected user names in the order of query()
     */
    #[Test]
    #[DataProviderExternal(OrmPagingTest::class, 'orders')]
    #[TestDox('Users by $_dataName')]
    public function walksUsers(?string $path, SortOrder $order, array $expected): void
    {
        Directory::seed(self::createSchema());
        $mapping = self::mappings()->get('User');
        $sortBy = null === $path ? null : $mapping->resourceType->resolve(AttributePath::parse($path) ?? throw new \LogicException($path))?->path();
        $query = self::getContainer()->get('tacticmedia_scim_server.query_planner')->plan(new ListQuery(sortBy: $sortBy, sortOrder: $order, count: 1), $mapping);
        self::assertInstanceOf(ObjectQuery::class, $query);

        self::assertSame([$expected, [4]], $this->walk($mapping, $query, static fn (object $user): string => $user instanceof User ? $user->getUserName() : ''));
    }

    /**
     * @param list<string> $expected shift names
     */
    #[Test]
    #[DataProvider('shiftOrders')]
    #[TestDox('Shifts by $_dataName')]
    public function walksShifts(?string $member, SortOrder $order, array $expected): void
    {
        $mapping = $this->seedShifts();
        $query = new ObjectQuery(null, null === $member ? [] : [$this->at($mapping, $member)], $order, 1, 1);

        self::assertSame([$expected, [5]], $this->walk($mapping, $query, static fn (object $shift): string => $shift instanceof Shift ? $shift->name : ''));
    }

    /**
     * Ids ascend a to e. Ties by the id: a and d in endsAt, floor, rate and active; b and e in active.
     *
     * @return iterable<string, array{string|null, SortOrder, list<string>}>
     */
    public static function shiftOrders(): iterable
    {
        yield 'the int id with a prefix' => [null, SortOrder::Ascending, ['a', 'b', 'c', 'd', 'e']];
        yield 'a case-exact string descending' => ['name', SortOrder::Descending, ['e', 'd', 'c', 'b', 'a']];
        yield 'a nullable date ascending, absent last' => ['endsAt', SortOrder::Ascending, ['c', 'a', 'd', 'b', 'e']];
        yield 'a nullable date descending, absent first' => ['endsAt', SortOrder::Descending, ['b', 'e', 'a', 'd', 'c']];
        yield 'a nullable int ascending' => ['floor', SortOrder::Ascending, ['b', 'e', 'a', 'd', 'c']];
        yield 'a nullable int descending' => ['floor', SortOrder::Descending, ['c', 'a', 'd', 'e', 'b']];
        yield 'a nullable float ascending' => ['rate', SortOrder::Ascending, ['c', 'a', 'd', 'e', 'b']];
        yield 'a nullable float descending' => ['rate', SortOrder::Descending, ['b', 'e', 'a', 'd', 'c']];
        yield 'a nullable Boolean ascending' => ['active', SortOrder::Ascending, ['b', 'e', 'a', 'd', 'c']];
        yield 'a nullable Boolean descending' => ['active', SortOrder::Descending, ['c', 'a', 'd', 'b', 'e']];
    }

    #[Test]
    #[TestDox('The filter applies to every page and to totalResults')]
    public function filters(): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));
        $query = new ObjectQuery($plans->eq('active', true), [$plans->at('userName')], SortOrder::Ascending, 1, 2);

        self::assertSame([['alice', 'bjensen', 'omalley'], [3]], $this->walk($plans->mapping(), $query, static fn (object $user): string => $user instanceof User ? $user->getUserName() : ''));
    }

    #[Test]
    #[TestDox('count 0 gives totalResults, no object and no position')]
    public function countZero(): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));

        $page = self::store()->queryAfter($plans->mapping(), new ObjectQuery(null, [], SortOrder::Ascending, 1, 0), null);

        self::assertEquals(new CursorObjectPage([], 4, null), $page);
    }

    #[Test]
    #[TestDox('The last page has no position, also when it is full')]
    public function lastFullPage(): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));

        $page = self::store()->queryAfter($plans->mapping(), new ObjectQuery(null, [], SortOrder::Ascending, 1, 4), null);

        self::assertInstanceOf(CursorObjectPage::class, $page);
        self::assertSame([4, null], [\count($page->objects), $page->next]);
    }

    #[Test]
    #[TestDox('The relations to load are filled on the page objects')]
    public function preloads(): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));

        $users = self::store()->queryAfter($plans->mapping(), new ObjectQuery(null, [], SortOrder::Ascending, 1, 2, [$plans->at('manager')]), null)->objects ?? [];

        self::assertCount(2, $users);
        self::assertContainsOnlyInstancesOf(User::class, $users);
        self::assertFalse(self::getContainer()->get('doctrine.orm.entity_manager')->getUnitOfWork()->isUninitializedObject($users[1]->getManager()));
    }

    #[Test]
    #[TestDox('A sort member whose database value is no JSON scalar gives no keyset page')]
    public function binarySortMember(): void
    {
        $mapping = $this->seedShifts();

        self::assertNull(self::store()->queryAfter($mapping, new ObjectQuery(null, [$this->at($mapping, 'code')], SortOrder::Ascending, 1, 1), null));
    }

    #[Test]
    #[DataProvider('laterTypes')]
    #[TestDox('The type $_dataName of a DBAL 4 version after 4.0 has keyset pages')]
    public function namesLaterTypes(string $type): void
    {
        self::assertContains($type, new \ReflectionClassConstant(OrmKeyset::class, 'SCALAR_TYPES')->getValue());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function laterTypes(): iterable
    {
        foreach (['enum', 'datetime_utc', 'datetime_utc_immutable'] as $type) {
            yield $type => [$type];
        }
    }

    /**
     * @param list<int|float|string|bool|null> $position
     */
    #[Test]
    #[DataProvider('foreignPositions')]
    #[TestDox('A position $_dataName is invalidCursor')]
    public function rejectsPositions(array $position): void
    {
        $mapping = $this->seedShifts();

        $this->expectExceptionObject(ScimException::invalidCursor());

        self::store()->queryAfter($mapping, new ObjectQuery(null, [$this->at($mapping, 'endsAt')], SortOrder::Ascending, 1, 1), $position);
    }

    /**
     * @return iterable<string, array{list<int|float|string|bool|null>}>
     */
    public static function foreignPositions(): iterable
    {
        yield 'of another length' => [['s1']];
        yield 'with an id of another resource type' => [[0, '2026-01-01 10:00:00', 'x1']];
        yield 'with a value that the type cannot read' => [[0, 'not a date', 's1']];
        yield 'with an id that is no string' => [[0, '2026-01-01 10:00:00', 1]];
    }

    /**
     * @param \Closure(object): string $name
     *
     * @return array{list<string>, list<int>} the names in page order, and totalResults of each page
     */
    private function walk(ResourceMapping $mapping, ObjectQuery $query, \Closure $name): array
    {
        $names = [];
        $totals = [];
        $after = null;

        do {
            $page = self::store()->queryAfter($mapping, $query, $after);
            self::assertInstanceOf(CursorObjectPage::class, $page);
            $names = [...$names, ...array_map($name, $page->objects)];
            $totals[] = $page->totalResults;
            $after = null === $page->next ? null : $this->throughJson($page->next);
        } while (null !== $after && \count($totals) <= 10);

        return [$names, array_values(array_unique($totals))];
    }

    /**
     * As CursorCodec encodes and decodes it.
     *
     * @param list<int|float|string|bool|null> $position
     *
     * @return list<int|float|string|bool|null>
     */
    private function throughJson(array $position): array
    {
        $decoded = json_decode(json_encode($position, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), true, 4, \JSON_THROW_ON_ERROR);
        self::assertIsList($decoded);

        return array_map(static fn (mixed $value): int|float|string|bool|null => null === $value || \is_scalar($value) ? $value : throw new \LogicException('Not a scalar.'), $decoded);
    }

    private function seedShifts(): ResourceMapping
    {
        $entityManager = self::createSchema();
        $rows = [
            ['a', '2026-01-02 10:00:00', 3, 1.5, true],
            ['b', null, 1, null, false],
            ['c', '2026-01-01 10:00:00', null, 0.25, null],
            ['d', '2026-01-02 10:00:00', 3, 1.5, true],
            ['e', null, 2, 2.0, false],
        ];

        foreach ($rows as [$name, $endsAt, $floor, $rate, $active]) {
            $shift = new Shift($name);
            $shift->endsAt = null === $endsAt ? null : new \DateTimeImmutable($endsAt, new \DateTimeZone('UTC'));
            $shift->floor = $floor;
            $shift->rate = $rate;
            $shift->active = $active;
            $entityManager->persist($shift);
            $entityManager->flush();
        }

        $entityManager->clear();

        return new AttributeLoader()->load(Shift::class);
    }

    private function at(ResourceMapping $mapping, string $member): AttributeMapping
    {
        return array_find($mapping->attributes, static fn (AttributeMapping $attribute): bool => $attribute->member === $member) ?? throw new \LogicException($member);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'keyset'] + $options);
    }
}
