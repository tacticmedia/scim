<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Repository\PatchResult;
use TacticMedia\ScimServer\Repository\ResourcePatch;
use TacticMedia\ScimServer\Repository\ValuesChange;
use TacticMedia\ScimServer\Repository\ValuesStep;
use TacticMedia\ScimServerBundle\Object\CollectionDeltaStore;
use TacticMedia\ScimServerBundle\Object\DocumentMapper;
use TacticMedia\ScimServerBundle\Object\Instantiator;
use TacticMedia\ScimServerBundle\Object\ObjectResourceRepository;
use TacticMedia\ScimServerBundle\Query\QueryPlanner;
use TacticMedia\ScimServerBundle\Tests\Fixtures\ArrayObjectStore;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\UserRepositoryDecorator;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Account;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\HiddenUserFilter;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Team;

/**
 * DeltaRepository::patch() of the Group repository over the ORM store: users in members[type eq "User"], groups in
 * members[type eq "Group"].
 */
final class ObjectResourceRepositoryDeltaTest extends KernelTestCase
{
    use CreatesDocumentMapper;
    use CreatesSchema;
    use DeltaServer;
    private const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    private const string CHANGED = 'Failed to update. Resource has changed on the server.';

    private EntityManagerInterface $entityManager;

    private MockClock $clock;

    private AttributePath $members;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-06 10:00:00', 'UTC');
        Clock::set($this->clock);
        $this->entityManager = self::createSchema();
        $this->members = new AttributePath(self::GROUP, 'members');
    }

    #[\Override]
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    #[Test]
    #[TestDox('deltaAttributes() lists Group members; User groups, an inverse side, and a store without the capability have none')]
    public function listsDeltaAttributes(): void
    {
        $users = self::getContainer()->get('test.tacticmedia_scim_server.repository.user');
        self::assertInstanceOf(UserRepositoryDecorator::class, $users->inner);
        self::assertInstanceOf(ObjectResourceRepository::class, $users->inner->inner);

        self::assertEquals([$this->members], self::deltaGroups()->deltaAttributes());
        self::assertSame([], $users->inner->inner->deltaAttributes());
        self::assertSame([], $this->arrayTeams()->deltaAttributes());
    }

    #[Test]
    #[TestDox('patch() over a store without the capability is a LogicException')]
    public function patchNeedsDeltaStore(): void
    {
        $this->expectExceptionObject(new \LogicException(\sprintf('patch() needs a %s, and the store of the resource type Group is a %s.', CollectionDeltaStore::class, ArrayObjectStore::class)));

        $this->arrayTeams()->patch('t1', new ResourcePatch(null, [], null));
    }

    #[Test]
    #[TestDox('An Add inserts the new member, stamps meta.lastModified and changes meta.version, without loading the members')]
    public function addsMember(): void
    {
        [$alice, $bob] = $this->users('alice', 'bob');
        $group = $this->group('Staff', [$alice]);
        $before = self::deltaGroups()->get($this->id($group), excludedAttributes: [$this->members]);
        $this->clock->sleep(60);

        $result = $this->patch($group, null, $this->step(PatchOperationType::Add, ['value' => $this->id($bob)]));

        self::assertEquals([new ValuesChange($this->members, [['value' => $this->id($bob), 'type' => 'User']], [])], $result->changes);
        self::assertSame(self::deltaGroups()->get($this->id($group), excludedAttributes: [$this->members]), $result->document);
        self::assertNotSame($this->meta($before)['version'], $this->meta($result->document)['version']);
        self::assertSame('2026-11-06T10:01:00Z', $this->meta($result->document)['lastModified']);
        self::assertArrayNotHasKey('members', $result->document);
        $users = $group->getUsers();
        self::assertInstanceOf(PersistentCollection::class, $users);
        self::assertFalse($users->isInitialized());
        self::assertSame(['alice', 'bob'], $this->memberNames($group));
    }

    #[Test]
    #[TestDox('Steps without an effective change keep meta.lastModified and meta.version')]
    public function keepsMetaWithoutChange(): void
    {
        [$alice, $bob] = $this->users('alice', 'bob');
        $group = $this->group('Staff', [$alice]);
        $before = self::deltaGroups()->get($this->id($group), excludedAttributes: [$this->members]);
        $this->clock->sleep(60);

        $result = $this->patch(
            $group,
            null,
            $this->step(PatchOperationType::Add, ['value' => $this->id($alice), 'type' => 'User'], ['value' => strtoupper($this->id($alice))]),
            $this->step(PatchOperationType::Remove, ['value' => $this->id($bob)], ['value' => (string) Uuid::v7()], ['value' => 'not-a-uuid']),
            $this->step(PatchOperationType::Replace, ['value' => $this->id($alice)]),
        );

        self::assertSame([], $result->changes);
        self::assertSame($before, $result->document);
        self::assertSame(['alice'], $this->memberNames($group));
    }

    #[Test]
    #[TestDox('Steps that cancel out keep meta and report no change; remove then add of a member writes no row')]
    public function cancelledStepsChangeNothing(): void
    {
        [$alice, $bob] = $this->users('alice', 'bob');
        $group = $this->group('Staff', [$alice]);
        $before = self::deltaGroups()->get($this->id($group), excludedAttributes: [$this->members]);
        $this->clock->sleep(60);
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        $queries->reset();

        $readded = $this->patch($group, null, $this->step(PatchOperationType::Remove, ['value' => $this->id($alice)]), $this->step(PatchOperationType::Replace), $this->step(PatchOperationType::Add, ['value' => $this->id($alice)]));
        $writes = preg_grep('/^(INSERT|DELETE|UPDATE)/i', array_column($queries->getData()['default'], 'sql'));
        $dropped = $this->patch($group, null, $this->step(PatchOperationType::Add, ['value' => $this->id($bob)]), $this->step(PatchOperationType::Remove, ['value' => $this->id($bob)]));
        $alternated = $this->patch($group, null, $this->step(PatchOperationType::Remove, ['value' => $this->id($alice)]), $this->step(PatchOperationType::Add, ['value' => $this->id($alice)]), $this->step(PatchOperationType::Remove, ['value' => $this->id($alice)]));

        self::assertSame([[], $before, []], [$readded->changes, $readded->document, $writes]);
        self::assertSame([[], $before], [$dropped->changes, $dropped->document]);
        self::assertEquals([new ValuesChange($this->members, [], [['value' => $this->id($alice), 'type' => 'User']])], $alternated->changes);
        self::assertSame([], $this->memberNames($group));
    }

    #[Test]
    #[TestDox('Through the server, remove then add of a member keeps the ETag and dispatches no event')]
    public function cancelledStepsKeepETag(): void
    {
        [$alice] = $this->users('alice');
        $group = $this->group('Staff', [$alice]);
        $server = $this->serverOf(self::deltaGroups());
        $etag = self::request($server, 'GET', '/Groups/'.$this->id($group))->headers->get('ETag');
        $operations = [['op' => 'remove', 'path' => \sprintf('members[value eq "%s"]', $this->id($alice))], ['op' => 'add', 'path' => 'members', 'value' => [['value' => $this->id($alice)]]]];

        $response = self::request($server, 'PATCH', '/Groups/'.$this->id($group), ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => $operations]);

        self::assertSame([204, $etag, []], [$response->getStatusCode(), $response->headers->get('ETag'), $this->events]);
    }

    #[Test]
    #[TestDox('Remove and Replace route users and nested groups by type, else by the collection that has or knows the id')]
    public function routesMembers(): void
    {
        [$alice, $bob, $carol] = $this->users('alice', 'bob', 'carol');
        $sales = $this->group('Sales');
        $ops = $this->group('Ops');
        $group = $this->group('Staff', [$alice, $bob], [$sales]);

        $result = $this->patch(
            $group,
            null,
            $this->step(PatchOperationType::Remove, ['value' => strtoupper($this->id($bob))]),
            $this->step(PatchOperationType::Replace, ['value' => $this->id($alice)], ['value' => $this->id($carol), 'type' => 'user'], ['value' => $this->id($ops)], ['value' => $this->id($carol)]),
        );

        self::assertEquals([
            new ValuesChange($this->members, [], [['value' => $this->id($bob), 'type' => 'User']]),
            new ValuesChange($this->members, [['value' => $this->id($carol), 'type' => 'User'], ['value' => $this->id($ops), 'type' => 'Group']], [['value' => $this->id($sales), 'type' => 'Group']]),
        ], $result->changes);
        self::assertSame(['alice', 'carol', 'Ops'], $this->memberNames($group));
    }

    #[Test]
    #[TestDox('An unknown or malformed member id of an Add or Replace is 400 invalidValue, and nothing is written')]
    public function unknownMemberIsInvalidValue(): void
    {
        [$alice, $bob] = $this->users('alice', 'bob');
        $sales = $this->group('Sales');
        $group = $this->group('Staff', [$alice]);
        $unknown = (string) Uuid::v7();
        $id = $this->id($group);

        $this->assertScimError(400, \sprintf('Attribute "members": "%s", "not-a-uuid" is not the id of a User or Group.', $unknown), fn (): PatchResult => $this->patch($group, ['displayName' => 'Renamed'], $this->step(PatchOperationType::Add, ['value' => $this->id($bob)], ['value' => $unknown], ['value' => 'not-a-uuid'])));
        $this->assertScimError(400, \sprintf('Attribute "members": "%s" is not the id of a User.', $this->id($sales)), fn (): PatchResult => $this->patch($this->find($id), null, $this->step(PatchOperationType::Replace, ['value' => $this->id($sales), 'type' => 'User'], ['value' => $unknown])));

        self::assertSame('Staff', self::deltaGroups()->get($id)['displayName'] ?? null);
        self::assertSame(['alice'], $this->memberNames($this->find($id)));
    }

    #[Test]
    #[TestDox('patch() writes the document without touching the members')]
    public function writesDocument(): void
    {
        [$alice, $bob] = $this->users('alice', 'bob');
        $group = $this->group('Staff', [$alice]);
        $this->clock->sleep(60);

        $result = $this->patch($group, ['displayName' => 'Crew'], $this->step(PatchOperationType::Add, ['value' => $this->id($bob)]));
        $renamed = $this->patch($this->find($this->id($group)), ['displayName' => 'Team']);

        self::assertSame(['Crew', '2026-11-06T10:01:00Z'], [$result->document['displayName'] ?? null, $this->meta($result->document)['lastModified']]);
        self::assertSame([[], 'Team'], [$renamed->changes, $renamed->document['displayName'] ?? null]);
        self::assertSame(['alice', 'bob'], $this->memberNames($this->find($this->id($group))));
    }

    #[Test]
    #[TestDox('patch() is 412 for another version, 404 for an unknown id, and 412 for an unknown id with a version')]
    public function checksVersionAndExistence(): void
    {
        [$alice] = $this->users('alice');
        $group = $this->group('Staff');
        $add = $this->step(PatchOperationType::Add, ['value' => $this->id($alice)]);
        $unknown = (string) Uuid::v7();

        $this->assertScimError(412, self::CHANGED, fn (): PatchResult => self::deltaGroups()->patch($this->id($group), new ResourcePatch(null, [$add], 'W/"7"')));
        $this->assertScimError(404, \sprintf('Resource %s not found.', $unknown), static fn (): PatchResult => self::deltaGroups()->patch($unknown, new ResourcePatch(null, [$add], null)));
        $this->assertScimError(412, self::CHANGED, static fn (): PatchResult => self::deltaGroups()->patch($unknown, new ResourcePatch(null, [$add], 'W/"1"')));

        $result = self::deltaGroups()->patch($this->id($group), new ResourcePatch(null, [$add], 'W/"1"'));

        self::assertCount(1, $result->changes);
    }

    #[Test]
    #[TestDox('Doctrine SQL filters apply: a hidden user is unknown to an Add, and a Replace keeps a hidden member')]
    public function honorsSqlFilters(): void
    {
        [$alice, $bjensen] = $this->users('alice', 'bjensen');
        $group = $this->group('Staff', [$bjensen]);
        $this->entityManager->getConfiguration()->addFilter('hidden_user', HiddenUserFilter::class);
        $this->entityManager->getFilters()->enable('hidden_user')->setParameter('userName', 'bjensen');

        $this->assertScimError(400, \sprintf('Attribute "members": "%s" is not the id of a User or Group.', $this->id($bjensen)), fn (): PatchResult => $this->patch($group, null, $this->step(PatchOperationType::Add, ['value' => $this->id($bjensen)])));
        $result = $this->patch($this->find($this->id($group)), null, $this->step(PatchOperationType::Replace, ['value' => $this->id($alice)]));
        $this->entityManager->getFilters()->disable('hidden_user');

        self::assertEquals([new ValuesChange($this->members, [['value' => $this->id($alice), 'type' => 'User']], [])], $result->changes);
        self::assertSame(['alice', 'bjensen'], $this->memberNames($this->find($this->id($group))));
    }

    #[Test]
    #[TestDox('A member add or remove on a group of 20,000 members runs the statements of one on a group of one member')]
    public function runsFixedStatementCount(): void
    {
        [$alice, $bob] = $this->users('alice', 'bob');
        $small = $this->group('Small', [$alice]);
        $large = $this->group('Large', [$alice]);
        $this->addMembers($large, 19_999);
        $this->entityManager->clear();
        $server = $this->serverOf(self::deltaGroups());
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        $counts = [];

        foreach ([$small, $large] as $group) {
            foreach ([['op' => 'add', 'path' => 'members', 'value' => [['value' => $this->id($bob)]]], ['op' => 'remove', 'path' => \sprintf('members[value eq "%s"]', $this->id($alice))]] as $operation) {
                $this->clock->sleep(1);
                $queries->reset();
                $response = self::request($server, 'PATCH', '/Groups/'.$this->id($group), ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [$operation]]);
                self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
                $counts[$group->getName()][] = \count($queries->getData()['default']);
            }
        }

        self::assertSame([14, 13], $counts['Small']);
        self::assertSame($counts['Small'], $counts['Large']);
        self::assertSame(20_001, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM app_group_user'));
    }

    /**
     * @param array<string, mixed>|null $document
     */
    private function patch(Group $group, ?array $document, ValuesStep ...$steps): PatchResult
    {
        return self::deltaGroups()->patch($this->id($group), new ResourcePatch($document, array_values($steps), null));
    }

    private function arrayTeams(): ObjectResourceRepository
    {
        $mappings = self::mappings(['User' => Account::class, 'Group' => Team::class]);
        $stores = new ServiceLocator([ArrayObjectStore::class => static fn (): ArrayObjectStore => new ArrayObjectStore()]);
        $mapper = new DocumentMapper($mappings, self::converters(), $stores);

        return new ObjectResourceRepository('Group', $mappings, new QueryPlanner($mappings, self::converters()), $mapper, new Instantiator(new ServiceLocator([]), $mapper), $stores, self::converters());
    }

    /**
     * @param array<string, string> ...$values
     */
    private function step(PatchOperationType $type, array ...$values): ValuesStep
    {
        return new ValuesStep($this->members, $type, array_values($values));
    }

    /**
     * @return list<User>
     */
    private function users(string ...$userNames): array
    {
        $users = [];

        foreach ($userNames as $userName) {
            $this->entityManager->persist($users[] = new User($userName));
        }

        $this->entityManager->flush();

        return $users;
    }

    /**
     * @param list<User>  $users
     * @param list<Group> $subgroups
     */
    private function group(string $name, array $users = [], array $subgroups = []): Group
    {
        $group = new Group($name);
        array_map($group->addUser(...), $users);
        array_map($group->addSubgroup(...), $subgroups);
        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return $group;
    }

    private function find(string $id): Group
    {
        $group = $this->entityManager->find(Group::class, Uuid::fromString($id));
        self::assertInstanceOf(Group::class, $group);

        return $group;
    }

    /**
     * Inserts users and their rows in the join table with DBAL, 100 per statement.
     */
    private function addMembers(Group $group, int $count): void
    {
        $connection = $this->entityManager->getConnection();
        $users = $this->entityManager->getClassMetadata(User::class);
        $fields = ['id', 'userName', 'enabled', 'createdAt', 'updatedAt', 'version'];
        $types = [UuidType::NAME, Types::STRING, Types::BOOLEAN, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::INTEGER];
        $joinTable = $this->entityManager->getClassMetadata(Group::class)->getAssociationMapping('users')->joinTable ?? throw new \LogicException('No join table.');
        $now = new \DateTimeImmutable('2026-11-06 10:00:00');

        foreach (array_chunk(range(1, $count), 100) as $chunk) {
            $ids = array_map(Uuid::v7(...), $chunk);
            $rows = array_map(static fn (int $index, Uuid $id): array => [$id, 'member'.$index, true, $now, $now, 1], $chunk, $ids);
            $connection->executeStatement($this->insert($users->getTableName(), array_map($users->getColumnName(...), $fields), \count($chunk)), array_merge(...$rows), array_merge(...array_fill(0, \count($chunk), $types)));
            $joinRows = array_map(static fn (Uuid $id): array => [$group->getId(), $id], $ids);
            $connection->executeStatement($this->insert($joinTable->name, [$joinTable->joinColumns[0]->name, $joinTable->inverseJoinColumns[0]->name], \count($chunk)), array_merge(...$joinRows), array_fill(0, 2 * \count($chunk), $types[0]));
        }
    }

    /**
     * @param list<string> $columns
     */
    private function insert(string $table, array $columns, int $rowCount): string
    {
        $row = '('.implode(', ', array_fill(0, \count($columns), '?')).')';

        return \sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, $rowCount, $row)));
    }

    /**
     * @return list<string> user names, then group names, each sorted
     */
    private function memberNames(Group $group): array
    {
        $this->entityManager->clear();
        $group = $this->find((string) $group->getId());
        $users = $group->getUsers()->map(static fn (User $user): string => $user->getUserName())->getValues();
        $groups = $group->getSubgroups()->map(static fn (Group $subgroup): string => $subgroup->getName())->getValues();
        sort($users);
        sort($groups);

        return [...$users, ...$groups];
    }

    private function id(User|Group $resource): string
    {
        return (string) $resource->getId();
    }

    /**
     * @param array<string, mixed>|null $document
     *
     * @return array<mixed>
     */
    private function meta(?array $document): array
    {
        self::assertIsArray($document['meta'] ?? null);

        return $document['meta'];
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function assertScimError(int $status, string $detail, \Closure $call): void
    {
        try {
            $call();
            self::fail('No ScimException.');
        } catch (ScimException $scimException) {
            self::assertSame([$status, $detail, 400 === $status ? ScimErrorType::InvalidValue : null], [$scimException->status, $scimException->detail, $scimException->scimType]);
        }
    }
}
