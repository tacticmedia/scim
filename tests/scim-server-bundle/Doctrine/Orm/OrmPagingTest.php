<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use Doctrine\ORM\PersistentCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServerBundle\Object\ObjectPage;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

final class OrmPagingTest extends KernelTestCase
{
    use CreatesOrmStore;

    /**
     * @param list<string> $expected user names in page order; the ids ascend in the order bjensen, jsmith, omalley, alice
     */
    #[Test]
    #[DataProvider('orders')]
    #[DataProvider('offsetOrders')]
    #[TestDox('Sorts by $_dataName')]
    public function sorts(?string $path, SortOrder $order, array $expected): void
    {
        Directory::seed(self::createSchema());
        $mapping = self::mappings()->get('User');
        $sortBy = null === $path ? null : $mapping->resourceType->resolve(AttributePath::parse($path) ?? throw new \LogicException($path))?->path();
        $query = self::getContainer()->get('tacticmedia_scim_server.query_planner')->plan(new ListQuery(sortBy: $sortBy, sortOrder: $order, count: 10), $mapping);
        self::assertInstanceOf(ObjectQuery::class, $query);

        $page = self::store()->query($mapping, $query);

        self::assertSame([$expected, 4], [$this->userNames($page), $page->totalResults]);
    }

    /**
     * @return iterable<string, array{string|null, SortOrder, list<string>}>
     */
    public static function orders(): iterable
    {
        yield 'the id ascending without sortBy' => [null, SortOrder::Descending, ['bjensen', 'jsmith', 'omalley', 'alice']];
        yield 'userName ascending' => ['userName', SortOrder::Ascending, ['alice', 'bjensen', 'jsmith', 'omalley']];
        yield 'givenName ascending, case-folded, absent last' => ['name.givenName', SortOrder::Ascending, ['alice', 'bjensen', 'jsmith', 'omalley']];
        yield 'givenName descending, absent first' => ['name.givenName', SortOrder::Descending, ['omalley', 'jsmith', 'bjensen', 'alice']];
        yield 'externalId ascending, case-exact, ties by id' => ['externalId', SortOrder::Ascending, ['jsmith', 'bjensen', 'omalley', 'alice']];
        yield 'externalId descending, ties by id' => ['externalId', SortOrder::Descending, ['omalley', 'alice', 'bjensen', 'jsmith']];
        yield 'a slot, "" as absent' => ['emails.value', SortOrder::Ascending, ['bjensen', 'jsmith', 'omalley', 'alice']];
        yield 'slots ascending, the primary value else the first' => ['phoneNumbers.value', SortOrder::Ascending, ['bjensen', 'jsmith', 'alice', 'omalley']];
    }

    /**
     * Orders that have no keyset: {@see OrmKeysetTest} does not walk them.
     *
     * @return iterable<string, array{string, SortOrder, list<string>}>
     */
    public static function offsetOrders(): iterable
    {
        yield 'element sub-attribute ascending, the primary element else the first' => ['addresses.locality', SortOrder::Ascending, ['alice', 'jsmith', 'bjensen', 'omalley']];
        yield 'a DQL expression ascending' => ['name.formatted', SortOrder::Ascending, ['alice', 'bjensen', 'jsmith', 'omalley']];
    }

    /**
     * @param int<1, max>  $startIndex
     * @param int<0, max>  $count
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('pageCases')]
    #[TestDox('Pages $_dataName')]
    public function pages(bool $activeOnly, int $startIndex, int $count, array $expected, int $totalResults): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));
        $filter = $activeOnly ? $plans->eq('active', true) : null;

        $page = self::store()->query($plans->mapping(), new ObjectQuery($filter, [$plans->at('userName')], SortOrder::Ascending, $startIndex, $count));

        self::assertSame([$expected, $totalResults], [$this->userNames($page), $page->totalResults]);
    }

    /**
     * @return iterable<string, array{bool, int<1, max>, int<0, max>, list<string>, int}>
     */
    public static function pageCases(): iterable
    {
        yield 'from startIndex 2, count 2' => [false, 2, 2, ['bjensen', 'jsmith'], 4];
        yield 'with count 0' => [false, 1, 0, [], 4];
        yield 'past the end' => [false, 5, 10, [], 4];
        yield 'the last page of a filter' => [true, 3, 10, ['omalley'], 3];
    }

    #[Test]
    #[TestDox('The relations to load are filled on the page objects, the others stay lazy')]
    public function preloadsTheRelationsToLoad(): void
    {
        $plans = new Plans(self::mappings(), 'User', Directory::seed(self::createSchema()));
        $unitOfWork = self::getContainer()->get('doctrine.orm.entity_manager')->getUnitOfWork();

        $users = self::store()->query($plans->mapping(), new ObjectQuery(null, [], SortOrder::Ascending, 1, 10, [$plans->at('groups'), $plans->at('manager')]))->objects;

        foreach ($users as $user) {
            self::assertInstanceOf(User::class, $user);
            self::assertTrue($this->collection($user->getGroups())->isInitialized());
            self::assertFalse($unitOfWork->isUninitializedObject($user->getManager()));
        }
    }

    #[Test]
    #[TestDox('Without relations to load, collections stay lazy')]
    public function loadsNothingElse(): void
    {
        $plans = new Plans(self::mappings(), 'Group', Directory::seed(self::createSchema()));

        $groups = self::store()->query($plans->mapping(), new ObjectQuery(null, [], SortOrder::Ascending, 1, 10))->objects;

        self::assertCount(3, $groups);

        foreach ($groups as $group) {
            self::assertInstanceOf(Group::class, $group);
            self::assertFalse($this->collection($group->getUsers())->isInitialized());
        }
    }

    /**
     * @return list<string>
     */
    private function userNames(ObjectPage $page): array
    {
        return array_map(static fn (object $user): string => $user instanceof User ? $user->getUserName() : '', $page->objects);
    }

    /**
     * @param iterable<mixed> $collection
     *
     * @return PersistentCollection<array-key, mixed>
     */
    private function collection(iterable $collection): PersistentCollection
    {
        self::assertInstanceOf(PersistentCollection::class, $collection);

        return $collection;
    }
}
