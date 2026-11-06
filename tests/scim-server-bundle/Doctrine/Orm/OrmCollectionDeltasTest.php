<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\KernelInterface;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Crowd;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Player;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Squad;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;

/**
 * The CollectionDeltaStore methods of the ORM store on int ids with idPrefix: players "p<n>", squads "s<n>".
 */
final class OrmCollectionDeltasTest extends KernelTestCase
{
    use CreatesSchema;

    private EntityManagerInterface $entityManager;

    private MappingRegistry $mappings;

    private OrmObjectStore $store;

    #[\Override]
    protected function setUp(): void
    {
        $this->entityManager = self::createSchema();
        $this->mappings = new MappingRegistry(new AttributeLoader(), ['User' => Player::class, 'Group' => Squad::class]);
        $this->store = $this->storeOf($this->mappings);
    }

    #[Test]
    #[TestDox('supportsDeltas() is true only for an owning ManyToMany side of a versioned class')]
    public function supportsVersionedOwningSides(): void
    {
        $kernel = self::getContainer()->get('test.tacticmedia_scim_server.mapping_registry');
        $crowds = new MappingRegistry(new AttributeLoader(), ['User' => Player::class, 'Group' => Crowd::class]);
        $kernelStore = $this->storeOf($kernel);

        self::assertTrue($this->store->supportsDeltas($this->squads(), $this->at($this->squads(), 'players')));
        self::assertTrue($this->store->supportsDeltas($this->squads(), $this->at($this->squads(), 'squads')));
        self::assertTrue($kernelStore->supportsDeltas($kernel->get('Group'), $this->at($kernel->get('Group'), 'subgroups')));
        self::assertFalse($kernelStore->supportsDeltas($kernel->get('User'), $this->at($kernel->get('User'), 'groups')));
        self::assertFalse($kernelStore->supportsDeltas($kernel->get('User'), $this->at($kernel->get('User'), 'manager')));
        self::assertFalse($this->storeOf($crowds)->supportsDeltas($crowds->get('Group'), $this->at($crowds->get('Group'), 'players')));
    }

    #[Test]
    #[TestDox('lock() reloads the object; null for an unknown or malformed id and for another version')]
    public function lockReloads(): void
    {
        $squad = $this->squad('Red');
        $this->connection()->executeStatement('UPDATE delta_squad SET version = 5');

        $locked = $this->store->transactional($this->squads(), fn (): ?object => $this->store->lock($this->squads(), 's'.$squad->id, 5));

        self::assertSame($squad, $locked);
        self::assertSame(5, $squad->version);
        self::assertNull($this->store->transactional($this->squads(), fn (): ?object => $this->store->lock($this->squads(), 's'.$squad->id, 4)));
        self::assertNull($this->store->transactional($this->squads(), fn (): ?object => $this->store->lock($this->squads(), 's999')));
        self::assertNull($this->store->transactional($this->squads(), fn (): ?object => $this->store->lock($this->squads(), 'p'.$squad->id)));
    }

    #[Test]
    #[TestDox('elementIds() gives every element id, or the given ids that are elements, without loading the collection')]
    public function readsElementIds(): void
    {
        [$alice, $bob, $carol] = $this->players('alice', 'bob', 'carol');
        $squad = $this->squad('Red', [$alice, $carol]);
        $squad->addSquad($blue = $this->squad('Blue'));

        $this->entityManager->flush();
        $this->entityManager->clear();

        $squad = $this->entityManager->find(Squad::class, $squad->id);
        self::assertInstanceOf(Squad::class, $squad);

        self::assertEqualsCanonicalizing(['p'.$alice->id, 'p'.$carol->id], $this->store->elementIds($this->squads(), $squad, $this->at($this->squads(), 'players')));
        self::assertSame(['p'.$carol->id], $this->store->elementIds($this->squads(), $squad, $this->at($this->squads(), 'players'), ['p'.$bob->id, 'p'.$carol->id, 'p999', 'x']));
        self::assertSame(['s'.$blue->id], $this->store->elementIds($this->squads(), $squad, $this->at($this->squads(), 'squads'), ['s'.$blue->id]));
        self::assertSame([], $this->store->elementIds($this->squads(), $squad, $this->at($this->squads(), 'players'), []));
        $players = $squad->getPlayers();
        self::assertInstanceOf(PersistentCollection::class, $players);
        self::assertFalse($players->isInitialized());
    }

    #[Test]
    #[TestDox('addElements() inserts the rows of the known ids and returns the unknown ids; removeElements() deletes rows')]
    public function writesJoinRows(): void
    {
        [$alice, $bob, $carol] = $this->players('alice', 'bob', 'carol');
        $squad = $this->squad('Red', [$alice]);
        $blue = $this->squad('Blue');
        $players = $this->at($this->squads(), 'players');

        $unknown = $this->store->transactional($this->squads(), fn (): array => $this->store->addElements($this->squads(), $squad, $players, ['p'.$bob->id, 'p999', 'x', 's'.$blue->id, 'p'.$carol->id]));
        $this->store->transactional($this->squads(), fn (): array => $this->store->addElements($this->squads(), $squad, $this->at($this->squads(), 'squads'), ['s'.$blue->id]));

        self::assertSame(['p999', 'x', 's'.$blue->id], $unknown);
        self::assertEqualsCanonicalizing([$alice->id, $bob->id, $carol->id], $this->rows('delta_squad_player', 'player_id'));
        self::assertSame([$blue->id], $this->rows('delta_squad_squad', 'child_id'));

        $this->store->transactional($this->squads(), fn () => $this->store->removeElements($this->squads(), $squad, $players, ['p'.$alice->id, 'p'.$carol->id]));
        $this->store->transactional($this->squads(), fn () => $this->store->removeElements($this->squads(), $squad, $players, []));

        self::assertSame([$bob->id], $this->rows('delta_squad_player', 'player_id'));
        self::assertSame(1, $squad->version);
    }

    #[Test]
    #[TestDox('The statements bind at most 1000 parameters')]
    public function chunksLargeIdLists(): void
    {
        $squad = $this->squad('Red');
        $players = $this->players(...array_map(static fn (int $index): string => 'player'.$index, range(1, 1100)));
        $ids = array_map(static fn (Player $player): string => 'p'.$player->id, $players);
        $collection = $this->at($this->squads(), 'players');
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        $queries->reset();

        $this->store->transactional($this->squads(), fn (): array => $this->store->addElements($this->squads(), $squad, $collection, $ids));
        $present = $this->store->elementIds($this->squads(), $squad, $collection, $ids);
        $this->store->transactional($this->squads(), fn () => $this->store->removeElements($this->squads(), $squad, $collection, \array_slice($ids, 0, 1050)));

        $parameterCounts = array_map(static fn (array $query): int => \count($query['params']), $queries->getData()['default']);
        self::assertSame($ids, $present);
        self::assertLessThanOrEqual(1000, max(0, ...$parameterCounts));
        self::assertCount(50, $this->rows('delta_squad_player', 'player_id'));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'deltas'] + $options);
    }

    private function storeOf(MappingRegistry $mappings): OrmObjectStore
    {
        return new OrmObjectStore(self::getContainer()->get('doctrine'), $mappings, new ServiceLocator([IdConverter::class => static fn (): ValueConverter => new IdConverter()]));
    }

    private function squads(): ResourceMapping
    {
        return $this->mappings->get('Group');
    }

    private function at(ResourceMapping $mapping, string $member): AttributeMapping
    {
        return array_find($mapping->attributes, static fn (AttributeMapping $attribute): bool => $attribute->member === $member) ?? throw new \LogicException($member);
    }

    /**
     * @return list<Player>
     */
    private function players(string ...$userNames): array
    {
        $players = array_values(array_map(static fn (string $userName): Player => new Player($userName), $userNames));
        array_walk($players, $this->entityManager->persist(...));
        $this->entityManager->flush();

        return $players;
    }

    /**
     * @param list<Player> $players
     */
    private function squad(string $name, array $players = []): Squad
    {
        $squad = new Squad($name);
        array_walk($players, $squad->addPlayer(...));
        $this->entityManager->persist($squad);
        $this->entityManager->flush();

        return $squad;
    }

    /**
     * @return list<int>
     */
    private function rows(string $table, string $column): array
    {
        return array_map(intval(...), $this->connection()->fetchFirstColumn(\sprintf('SELECT %s FROM %s ORDER BY %1$s', $column, $table)));
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
