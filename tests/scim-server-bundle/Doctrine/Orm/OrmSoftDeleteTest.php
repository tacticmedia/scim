<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\KernelInterface;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmQueryContext;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmQueryTranslator;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Mapping\SoftDelete;
use TacticMedia\ScimServerBundle\Object\CursorObjectPage;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\Plan\Exists;
use TacticMedia\ScimServerBundle\Query\Plan\Node;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Player;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Squad;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

/**
 * The directory of {@see Directory} with bjensen soft-deleted, and players with a bool flag.
 */
final class OrmSoftDeleteTest extends KernelTestCase
{
    use CreatesOrmStore;

    private EntityManagerInterface $entityManager;

    private Directory $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->entityManager = self::createSchema();
        $this->directory = Directory::seed($this->entityManager);
        $bjensen = $this->entityManager->find(User::class, $this->directory->id('bjensen'));
        self::assertInstanceOf(User::class, $bjensen);
        $this->softDelete(self::users())->delete($bjensen, new \DateTimeImmutable('2026-11-06 10:00:00'));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    #[Test]
    #[TestDox('find(), findMany() and lock() skip a soft-deleted object')]
    public function findSkipsDeleted(): void
    {
        $bjensen = (string) $this->directory->id('bjensen');
        $alice = (string) $this->directory->id('alice');

        self::assertNull(self::store()->find(self::users(), $bjensen));
        self::assertSame([$alice], array_keys(self::store()->findMany(self::users(), [$bjensen, $alice])));
        self::assertNull(self::store()->transactional(self::users(), static fn (): ?object => self::store()->lock(self::users(), $bjensen)));
    }

    #[Test]
    #[TestDox('query() and queryAfter() skip and do not count a soft-deleted object')]
    public function queriesSkipDeleted(): void
    {
        $page = self::store()->query(self::users(), new ObjectQuery(null, [], SortOrder::Ascending, 1, 10));
        $cursor = self::store()->queryAfter(self::users(), new ObjectQuery(null, [], SortOrder::Ascending, 1, 2), null);

        self::assertEqualsCanonicalizing(['jsmith', 'omalley', 'alice'], $this->userNames($page->objects));
        self::assertSame(3, $page->totalResults);
        self::assertInstanceOf(CursorObjectPage::class, $cursor);
        self::assertSame(3, $cursor->totalResults);
        self::assertNotContains('bjensen', $this->userNames($cursor->objects));
    }

    #[Test]
    #[TestDox('A filter on a reference or a member does not match a soft-deleted target')]
    public function existsSkipsDeletedTargets(): void
    {
        $users = new Plans(self::mappings(), 'User', $this->directory);
        $groups = new Plans(self::mappings(), 'Group', $this->directory);

        $managed = self::store()->query(self::users(), new ObjectQuery($users->exists('manager'), [], SortOrder::Ascending, 1, 10));
        $withBjensen = self::store()->query($groups->mapping(), new ObjectQuery($groups->exists('members', $groups->idOf('User', 'bjensen'), 'User'), [], SortOrder::Ascending, 1, 10));

        self::assertSame(['alice'], $this->userNames($managed->objects));
        self::assertSame(0, $withBjensen->totalResults);
    }

    #[Test]
    #[TestDox('The EXISTS of a reference guards the target: IS NULL for a date, = false for a bool')]
    public function existsGuardsTheTarget(): void
    {
        $users = new Plans(self::mappings(), 'User', $this->directory);
        $squads = $this->squads()->get('Group');

        self::assertSame('EXISTS (SELECT 1 FROM '.User::class.' t0 WHERE t0 = r.manager AND t0.deletedAt IS NULL AND t0.id = :scim_p0)', $this->condition(self::mappings(), self::users(), $users->exists('manager', $users->idOf('User', 'jsmith'))));
        self::assertSame('EXISTS (SELECT 1 FROM '.Squad::class.' o0 JOIN o0.players t0 WHERE o0 = r AND t0.deleted = false)', $this->condition($this->squads(), $squads, new Exists($this->at($squads, 'players'))));
        self::assertSame('EXISTS (SELECT 1 FROM '.Squad::class.' o0 JOIN o0.squads t0 WHERE o0 = r)', $this->condition($this->squads(), $squads, new Exists($this->at($squads, 'squads'))));
    }

    #[Test]
    #[TestDox('findDeleted() finds the soft-deleted object that matches, and no live one')]
    public function findsDeleted(): void
    {
        $users = new Plans(self::mappings(), 'User', $this->directory);

        self::assertSame('bjensen', self::user(self::store()->findDeleted(self::users(), $users->eq('userName', 'BJENSEN')))->getUserName());
        self::assertNull(self::store()->findDeleted(self::users(), $users->eq('userName', 'jsmith')));
        self::assertNull(self::store()->findDeleted(self::mappings()->get('Group'), new Plans(self::mappings(), 'Group', $this->directory)->eq('displayName', 'Staff')));
    }

    #[Test]
    #[TestDox('The delta path does not see a soft-deleted element: not present, not removable, unknown to an add')]
    public function deltasSkipDeletedElements(): void
    {
        $alice = new Player('alice');
        $bob = new Player('bob');
        $red = new Squad('Red');
        $red->addPlayer($alice);
        $red->addPlayer($bob);

        $blue = new Squad('Blue');
        array_map($this->entityManager->persist(...), [$alice, $bob, $red, $blue]);
        $alice->deleted = true;
        $this->entityManager->flush();
        $squads = $this->squads()->get('Group');
        $players = $this->at($squads, 'players');
        $store = $this->squadStore();

        self::assertSame(['p'.$bob->id], $store->elementIds($squads, $red, $players));
        self::assertSame(['p'.$bob->id], $store->elementIds($squads, $red, $players, ['p'.$alice->id, 'p'.$bob->id]));
        self::assertSame(['p'.$alice->id], $store->transactional($squads, static fn (): array => $store->addElements($squads, $blue, $players, ['p'.$alice->id])));
        self::assertNull($store->find($this->squads()->get('User'), 'p'.$alice->id));
    }

    #[Test]
    #[TestDox('isDeleted() loads an uninitialized proxy')]
    public function readsAProxy(): void
    {
        $manager = $this->entityManager->find(User::class, $this->directory->id('jsmith'))?->getManager();
        self::assertInstanceOf(User::class, $manager);
        self::assertTrue($this->entityManager->getUnitOfWork()->isUninitializedObject($manager));

        self::assertTrue($this->softDelete(self::users())->isDeleted($manager));
    }

    #[Test]
    #[TestDox('First use fails for a soft-delete property that is not a Doctrine field')]
    public function softDeleteNeedsAField(): void
    {
        $users = self::users();
        $mapping = new ResourceMapping($users->class, $users->resourceType, $users->id, $users->attributes, $users->constructorParameters, softDelete: new SoftDelete(User::class, 'groups', null, true));

        $this->expectExceptionObject(new MappingException([User::class.'::$groups: #[ScimSoftDelete] needs a Doctrine field, but $groups is an association. Map a column, or map the association to a reference attribute.']));

        self::store()->find($mapping, 'x');
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'deltas'] + $options);
    }

    private function condition(MappingRegistry $mappings, ResourceMapping $mapping, Node $node): string
    {
        return new OrmQueryTranslator($mappings)->condition($node, new OrmQueryContext($this->entityManager, $mapping->class));
    }

    private function softDelete(ResourceMapping $mapping): SoftDelete
    {
        return $mapping->softDelete ?? throw new \LogicException($mapping->class.' has no soft delete.');
    }

    private static function users(): ResourceMapping
    {
        return self::mappings()->get('User');
    }

    private function squads(): MappingRegistry
    {
        return new MappingRegistry(new AttributeLoader(), ['User' => Player::class, 'Group' => Squad::class]);
    }

    private function squadStore(): OrmObjectStore
    {
        return new OrmObjectStore(self::getContainer()->get('doctrine'), $this->squads(), new ServiceLocator([IdConverter::class => static fn (): ValueConverter => new IdConverter()]));
    }

    private function at(ResourceMapping $mapping, string $member): AttributeMapping
    {
        return array_find($mapping->attributes, static fn (AttributeMapping $attribute): bool => $attribute->member === $member) ?? throw new \LogicException($member);
    }

    private static function user(?object $user): User
    {
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * @param list<object> $users
     *
     * @return list<string>
     */
    private function userNames(array $users): array
    {
        return array_map(static fn (object $user): string => self::user($user)->getUserName(), $users);
    }
}
