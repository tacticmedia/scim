<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\HiddenUserFilter;

final class ObjectResourceRepositoryTest extends KernelTestCase
{
    use CreatesSchema;
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const string CHANGED = 'Failed to update. Resource has changed on the server.';

    private EntityManagerInterface $entityManager;

    private MockClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-06 10:00:00', 'UTC');
        Clock::set($this->clock);
        $this->entityManager = self::createSchema();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    #[Test]
    #[TestDox('create() writes the document, stamps meta.lastModified and returns the stored document')]
    public function createStoresTheDocument(): void
    {
        $created = self::repository('user')->create(['userName' => 'bjensen', 'name' => ['givenName' => 'Barbara'], 'active' => false, 'emails' => [['value' => 'bjensen@example.com', 'type' => 'work']]]);
        $this->entityManager->clear();
        $user = $this->entityManager->find(User::class, Uuid::fromString($this->id($created)));

        self::assertSame([
            'schemas' => [self::USER],
            'id' => $this->id($created),
            'meta' => ['created' => '2026-11-06T10:00:00Z', 'lastModified' => '2026-11-06T10:00:00Z', 'version' => 'W/"1"'],
            'userName' => 'bjensen',
            'name' => ['givenName' => 'Barbara', 'formatted' => 'Barbara'],
            'active' => false,
            'emails' => [['value' => 'bjensen@example.com', 'type' => 'work', 'primary' => true]],
        ], $created);
        self::assertSame(['bjensen', 'bjensen@example.com', false], [$user?->getUserName(), $user?->getEmail(), $user?->isEnabled()]);
    }

    #[Test]
    #[TestDox('get() returns the stored document, and null for an unknown or malformed id')]
    public function getFindsById(): void
    {
        $created = self::repository('user')->create(['userName' => 'bjensen']);
        $this->entityManager->clear();

        self::assertSame($created, self::repository('user')->get($this->id($created)));
        self::assertNull(self::repository('user')->get((string) Uuid::v7()));
        self::assertNull(self::repository('user')->get('not-a-uuid'));
    }

    #[Test]
    #[TestDox('replace() stamps meta.lastModified only when the document changed')]
    public function replaceStampsChangesOnly(): void
    {
        $id = $this->id(self::repository('group')->create(['displayName' => 'Staff']));
        $this->clock->sleep(60);

        $unchanged = self::repository('group')->replace($id, ['displayName' => 'Staff']);
        $changed = self::repository('group')->replace($id, ['displayName' => 'Sales']);

        self::assertSame('2026-11-06T10:00:00Z', $this->meta($unchanged)['lastModified']);
        self::assertSame(['2026-11-06T10:00:00Z', '2026-11-06T10:01:00Z', 'Sales'], [$this->meta($changed)['created'], $this->meta($changed)['lastModified'], $changed['displayName']]);
    }

    #[Test]
    #[TestDox('replace() diffs the members: removes, keeps and adds by id, typed or untyped')]
    public function replaceDiffsMembers(): void
    {
        [$alice, $bob, $carol] = array_map(fn (string $userName): string => $this->id(self::repository('user')->create(['userName' => $userName])), ['alice', 'bob', 'carol']);
        $sales = $this->id(self::repository('group')->create(['displayName' => 'Sales']));
        $id = $this->id(self::repository('group')->create(['displayName' => 'Staff', 'members' => [['value' => $alice, 'type' => 'User'], ['value' => $bob]]]));
        $this->entityManager->clear();
        $this->clock->sleep(60);

        $replaced = self::repository('group')->replace($id, ['displayName' => 'Staff', 'members' => [['value' => $bob], ['value' => $carol, 'type' => 'User'], ['value' => $sales]]]);
        $this->entityManager->clear();
        $group = $this->entityManager->find(Group::class, Uuid::fromString($id));

        self::assertSame([[$bob, 'User'], [$carol, 'User'], [$sales, 'Group']], array_map(static fn (array $member): array => [$member['value'], $member['type']], $this->list($replaced['members'] ?? null)));
        self::assertSame(['bob', 'carol'], $group?->getUsers()->map(static fn (User $user): string => $user->getUserName())->getValues());
        self::assertSame(['Sales'], $group->getSubgroups()->map(static fn (Group $subgroup): string => $subgroup->getName())->getValues());
        self::assertSame('2026-11-06T10:01:00Z', $this->meta($replaced)['lastModified']);
    }

    #[Test]
    #[TestDox('References render the target id and display name, and an unknown target id is 400 invalidValue')]
    public function referencesResolveTargets(): void
    {
        $manager = $this->id(self::repository('user')->create(['userName' => 'bjensen', 'displayName' => 'Babs']));
        $report = self::repository('user')->create(['userName' => 'jsmith', self::ENTERPRISE => ['manager' => ['value' => $manager]]]);
        $group = $this->id(self::repository('group')->create(['displayName' => 'Staff', 'members' => [['value' => $manager]]]));
        $this->entityManager->clear();

        self::assertSame([self::ENTERPRISE => ['manager' => ['value' => $manager, 'displayName' => 'Babs']]], array_intersect_key($report, [self::ENTERPRISE => true]));
        self::assertSame([['value' => $group, 'display' => 'Staff']], self::repository('user')->get($manager)['groups'] ?? null);

        $this->expectExceptionObject(ScimException::invalidValue(\sprintf('Attribute "manager": "%s" is not the id of a User.', $group)));

        self::repository('user')->create(['userName' => 'omalley', self::ENTERPRISE => ['manager' => ['value' => $group]]]);
    }

    #[Test]
    #[TestDox('A write returns the members in the order of the next get(), so the ETag stays the same')]
    public function writesRenderMembersInStoredOrder(): void
    {
        [$alice, $bob] = array_map(fn (string $userName): string => $this->id(self::repository('user')->create(['userName' => $userName])), ['alice', 'bob']);
        $created = self::repository('group')->create(['displayName' => 'Staff', 'members' => [['value' => $bob], ['value' => $alice]]]);
        $this->entityManager->clear();
        $createdThenRead = self::repository('group')->get($this->id($created));
        $id = $this->id(self::repository('group')->create(['displayName' => 'Sales', 'members' => [['value' => $bob]]]));
        $this->entityManager->clear();
        $replaced = self::repository('group')->replace($id, ['displayName' => 'Sales', 'members' => [['value' => $bob], ['value' => $alice]]]);
        $this->entityManager->clear();

        self::assertSame([$created, $replaced], [$createdThenRead, self::repository('group')->get($id)]);
    }

    #[Test]
    #[TestDox('A manager that a Doctrine SQL filter hides is absent, and the report stays readable and writable')]
    public function hiddenManagerIsAbsent(): void
    {
        $manager = $this->id(self::repository('user')->create(['userName' => 'bjensen']));
        $report = $this->id(self::repository('user')->create(['userName' => 'jsmith', self::ENTERPRISE => ['department' => 'Sales', 'manager' => ['value' => $manager]]]));
        $this->entityManager->clear();
        $this->entityManager->getConfiguration()->addFilter('hidden_user', HiddenUserFilter::class);
        $this->entityManager->getFilters()->enable('hidden_user')->setParameter('userName', 'bjensen');

        self::assertSame([self::ENTERPRISE => ['department' => 'Sales']], array_intersect_key(self::repository('user')->get($report) ?? [], [self::ENTERPRISE => true]));
        self::assertSame('Ops', self::repository('user')->replace($report, ['userName' => 'jsmith', self::ENTERPRISE => ['department' => 'Ops']])[self::ENTERPRISE]['department'] ?? null);
    }

    #[Test]
    #[TestDox('delete() removes the object; an unknown id is 404 for replace() and delete()')]
    public function deleteRemovesTheObject(): void
    {
        $id = $this->id(self::repository('user')->create(['userName' => 'bjensen']));

        self::repository('user')->delete($id);

        self::assertNull(self::repository('user')->get($id));
        $this->assertScimError(404, null, \sprintf('Resource %s not found.', $id), static fn (): mixed => self::repository('user')->replace($id, ['userName' => 'bjensen']));
        $this->assertScimError(404, null, \sprintf('Resource %s not found.', $id), static fn () => self::repository('user')->delete($id));
    }

    #[Test]
    #[TestDox('list() filters, sorts, pages and counts every match')]
    public function listFiltersSortsAndPages(): void
    {
        array_map(fn (string $userName): array => self::repository('user')->create(['userName' => $userName]), ['alice', 'jane', 'bob', 'john']);
        $this->entityManager->clear();
        $sortBy = new AttributePath(self::USER, 'userName');

        $first = self::repository('user')->list(new ListQuery($this->filter('User', 'userName sw "J"'), $sortBy, SortOrder::Descending, 1, 1));
        $second = self::repository('user')->list(new ListQuery($this->filter('User', 'userName sw "J"'), $sortBy, SortOrder::Descending, 2, 1));

        self::assertSame([['john'], 2], [array_column($first->resources, 'userName'), $first->totalResults]);
        self::assertSame([['jane'], 2], [array_column($second->resources, 'userName'), $second->totalResults]);
    }

    #[Test]
    #[TestDox('list() with a filter that matches nothing by plan returns an empty result')]
    public function listWithConstantFalsePlan(): void
    {
        self::repository('user')->create(['userName' => 'bjensen']);

        $result = self::repository('user')->list(new ListQuery($this->filter('User', 'id eq "not-a-uuid"')));

        self::assertSame([[], 0], [$result->resources, $result->totalResults]);
    }

    #[Test]
    #[TestDox('replace() and delete() with a version that the resource no longer has are 412 and change nothing')]
    public function staleVersionFails(): void
    {
        $id = $this->id(self::repository('user')->create(['userName' => 'bjensen']));
        $this->entityManager->getConnection()->executeStatement("UPDATE app_user SET version = version + 1 WHERE userName = 'bjensen'");

        $this->assertScimError(412, null, self::CHANGED, static fn (): array => self::repository('user')->replace($id, ['userName' => 'bjensen', 'displayName' => 'Babs'], 'W/"1"'));
        $this->assertScimError(412, null, self::CHANGED, static fn () => self::repository('user')->delete($id, 'W/"1"'));
        $stored = self::repository('user')->get($id) ?? [];

        self::assertSame(['W/"2"', null], [$this->meta($stored)['version'] ?? null, $stored['displayName'] ?? null]);
    }

    #[Test]
    #[TestDox('replace() and delete() with the stored version write, and each replace() gives a new version')]
    public function currentVersionWrites(): void
    {
        $created = self::repository('user')->create(['userName' => 'bjensen']);
        $id = $this->id($created);

        $changed = self::repository('user')->replace($id, ['userName' => 'bjensen', 'displayName' => 'Babs'], 'W/"1"');
        $unchanged = self::repository('user')->replace($id, ['userName' => 'bjensen', 'displayName' => 'Babs'], 'W/"3"');
        self::repository('user')->delete($id, 'W/"4"');

        self::assertSame(['W/"1"', 'W/"3"', 'W/"4"'], array_map(fn (array $document): mixed => $this->meta($document)['version'] ?? null, [$created, $changed, $unchanged]));
        self::assertNull(self::repository('user')->get($id));
    }

    #[Test]
    #[TestDox('replace() and delete() with a version of a resource that another request deleted are 412')]
    public function versionOfDeletedResourceFails(): void
    {
        $id = $this->id(self::repository('user')->create(['userName' => 'bjensen']));
        $this->entityManager->getConnection()->executeStatement("DELETE FROM app_user WHERE userName = 'bjensen'");

        $this->assertScimError(412, null, self::CHANGED, static fn (): array => self::repository('user')->replace($id, ['userName' => 'bjensen'], 'W/"1"'));
        $this->assertScimError(412, null, self::CHANGED, static fn () => self::repository('user')->delete($id, 'W/"1"'));
    }

    #[Test]
    #[TestDox('A filter on meta.version compares the version number')]
    public function filtersByVersion(): void
    {
        $created = self::repository('user')->create(['userName' => 'bjensen']);
        $this->entityManager->clear();

        self::assertSame([$created], self::repository('user')->list(new ListQuery($this->filter('User', 'meta.version eq "W/\\"1\\""')))->resources);
        self::assertSame(0, self::repository('user')->list(new ListQuery($this->filter('User', 'meta.version eq "W/\\"2\\""')))->totalResults);
    }

    #[Test]
    #[TestDox('An int id carries the idPrefix in and out')]
    public function intIdsCarryThePrefix(): void
    {
        $created = self::repository('badge')->create(['serialNumber' => 'SN-1']);
        $this->entityManager->clear();

        self::assertSame(['b1', 'SN-1'], [$created['id'] ?? null, $created['serialNumber'] ?? null]);
        self::assertSame($created, self::repository('badge')->get('b1'));
        self::assertNull(self::repository('badge')->get('1'));
        self::assertSame([$created], self::repository('badge')->list(new ListQuery($this->filter('Badge', 'id eq "b1"')))->resources);
    }

    #[Test]
    #[TestDox('A value that the database holds already is 409 uniqueness')]
    public function duplicatesAreUniquenessConflicts(): void
    {
        self::repository('user')->create(['userName' => 'bjensen']);
        self::repository('group')->create(['displayName' => 'Staff']);
        $detail = 'One or more of the attribute values are already in use or are reserved.';

        $this->assertScimError(409, ScimErrorType::Uniqueness, $detail, static fn (): array => self::repository('user')->create(['userName' => 'bjensen']));
        $this->assertScimError(409, ScimErrorType::Uniqueness, $detail, static fn (): array => self::repository('group')->create(['displayName' => 'Staff']));
    }

    private static function repository(string $name): ResourceRepository
    {
        $repository = self::getContainer()->get('test.tacticmedia_scim_server.repository.'.$name);
        self::assertInstanceOf(ResourceRepository::class, $repository);

        return $repository;
    }

    private function filter(string $name, string $filter): \TacticMedia\ScimServer\Filter\Filter
    {
        $type = self::getContainer()->get('test.tacticmedia_scim_server.mapping_registry')->get($name)->resourceType;

        return new FilterResolver()->resolve(new FilterParser()->parse($filter), $type);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function id(array $document): string
    {
        self::assertIsString($document['id'] ?? null);

        return $document['id'];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<mixed>
     */
    private function meta(array $document): array
    {
        self::assertIsArray($document['meta'] ?? null);

        return $document['meta'];
    }

    /**
     * @return list<array<mixed>>
     */
    private function list(mixed $value): array
    {
        self::assertIsList($value);

        return array_values(array_filter($value, \is_array(...)));
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function assertScimError(int $status, ?ScimErrorType $scimType, string $detail, \Closure $call): void
    {
        try {
            $call();
        } catch (ScimException $scimException) {
            self::assertSame([$status, $scimType, $detail], [$scimException->status, $scimException->scimType, $scimException->detail]);

            return;
        }

        self::fail('No ScimException.');
    }
}
