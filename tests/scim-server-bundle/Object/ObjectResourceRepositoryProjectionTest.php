<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Object\DocumentMapper;
use TacticMedia\ScimServerBundle\Object\Instantiator;
use TacticMedia\ScimServerBundle\Object\ObjectResourceRepository;
use TacticMedia\ScimServerBundle\Query\QueryPlanner;
use TacticMedia\ScimServerBundle\Tests\Fixtures\ArrayObjectStore;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Account;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Crew;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Team;

/**
 * The repository over an ArrayObjectStore.
 */
final class ObjectResourceRepositoryProjectionTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use CreatesDocumentMapper;
    private const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    #[Test]
    #[TestDox('With meta.version, get() does not read the members that the response omits')]
    public function versionedGetSkipsOmittedRelations(): void
    {
        $team = new Team('Staff');
        $repository = $this->repository($team);
        $withoutMembers = ['schemas' => [self::GROUP], 'id' => 'team-1', 'meta' => ['version' => 'W/"7"'], 'displayName' => 'Staff'];

        self::assertSame($withoutMembers, $repository->get('team-1', excludedAttributes: [new AttributePath(self::GROUP, 'members')]));
        self::assertSame($withoutMembers, $repository->get('team-1', attributes: [new AttributePath(self::GROUP, 'displayName')]));
        self::assertSame(0, $team->memberReads);
        self::assertSame([['value' => 'acc-bjensen', 'display' => 'Anonymous', 'type' => 'User']], $repository->get('team-1', attributes: [new AttributePath(self::GROUP, 'members', 'value')])['members'] ?? null);
        self::assertSame(1, $team->memberReads);
    }

    #[Test]
    #[TestDox('Without meta.version, get() renders the members that the response omits, so the ETag stays the same')]
    public function unversionedGetRendersOmittedRelations(): void
    {
        $mappings = self::mappings(['User' => Account::class, 'Group' => Crew::class]);
        $crew = new Crew('Staff');
        $crew->addMember(new Account('bjensen'));

        $repository = $this->repositoryOf('Group', $mappings, new ArrayObjectStore()->add($mappings->get('Group'), $crew));
        $complete = $repository->get('crew-1');

        self::assertSame([['value' => 'acc-bjensen', 'display' => 'Anonymous', 'type' => 'User']], $complete['members'] ?? null);
        self::assertSame($complete, $repository->get('crew-1', excludedAttributes: [new AttributePath(self::GROUP, 'members')]));
    }

    #[Test]
    #[TestDox('create() works through the store of the mapping and without a clock service')]
    public function createUsesTheStoreOfTheMapping(): void
    {
        $repository = $this->repository(new Team('Staff'));

        self::assertSame(['schemas' => [self::GROUP], 'id' => 'team-1', 'meta' => ['version' => 'W/"7"'], 'displayName' => 'Sales'], $repository->create(['displayName' => 'Sales']));
        self::assertSame('Sales', $repository->get('team-1')['displayName'] ?? null);
    }

    #[Test]
    #[TestDox('create() gives meta.lastModified the instant of meta.created')]
    public function createStampsLastModifiedWithCreated(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-06T10:00:00Z'));
        $repository = $this->repositoryOf('User', self::kernelMappings(), new ArrayObjectStore(), new MockClock('2026-11-06 10:00:01', 'UTC'));

        self::assertSame(['created' => '2026-11-06T10:00:00Z', 'lastModified' => '2026-11-06T10:00:00Z', 'version' => 'W/"1"'], $repository->create(['userName' => 'bjensen'])['meta'] ?? null);
    }

    private function repository(Team $team): ObjectResourceRepository
    {
        $mappings = self::mappings(['User' => Account::class, 'Group' => Team::class]);
        $store = new ArrayObjectStore();
        $account = new Account('bjensen');
        $team->addMember($account);
        $store->add($mappings->get('User'), $account)->add($mappings->get('Group'), $team);

        return $this->repositoryOf('Group', $mappings, $store);
    }

    private function repositoryOf(string $name, MappingRegistry $mappings, ArrayObjectStore $store, ?ClockInterface $clock = null): ObjectResourceRepository
    {
        $stores = new ServiceLocator([ArrayObjectStore::class => static fn (): ArrayObjectStore => $store, OrmObjectStore::class => static fn (): ArrayObjectStore => $store]);
        $mapper = new DocumentMapper($mappings, self::converters(), $stores);

        return new ObjectResourceRepository($name, $mappings, new QueryPlanner($mappings, self::converters()), $mapper, new Instantiator(new ServiceLocator([]), $mapper), $stores, self::converters(), $clock);
    }
}
