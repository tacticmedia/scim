<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\Filter\SQLFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\TacticMediaScimServerBundle;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Device;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\HiddenUserFilter;

final class OrmObjectStoreTest extends KernelTestCase
{
    use CreatesOrmStore;

    #[Test]
    #[TestDox('find() loads by SCIM id; malformed and unknown ids give null')]
    public function findsById(): void
    {
        $directory = Directory::seed(self::createSchema());
        $store = self::store();
        $users = self::mappings()->get('User');

        self::assertSame('jsmith', self::user($store->find($users, (string) $directory->id('jsmith')))->getUserName());
        self::assertNull($store->find($users, 'not-a-uuid'));
        self::assertNull($store->find($users, (string) Uuid::v7()));
    }

    #[Test]
    #[TestDox('findMany() keys the found objects by the SCIM ids as given and skips malformed and unknown ids')]
    public function findsManyById(): void
    {
        $directory = Directory::seed(self::createSchema());
        $bjensen = (string) $directory->id('bjensen');
        $alice = (string) $directory->id('alice');

        $found = self::store()->findMany(self::mappings()->get('User'), [$alice, 'bad', (string) Uuid::v7(), $bjensen, strtoupper($bjensen)]);

        self::assertSame([$alice => 'alice', $bjensen => 'bjensen', strtoupper($bjensen) => 'bjensen'], array_map(static fn (object $user): string => self::user($user)->getUserName(), $found));
        self::assertSame([], self::store()->findMany(self::mappings()->get('User'), ['bad']));
    }

    #[Test]
    #[TestDox('findMany() binds at most 500 ids per query and finds the ids of every chunk')]
    public function findsManyInChunks(): void
    {
        $directory = Directory::seed(self::createSchema());
        $unknown = array_map(static fn (): string => (string) Uuid::v7(), range(1, 1100));
        $ids = [(string) $directory->id('bjensen'), ...\array_slice($unknown, 0, 600), (string) $directory->id('alice'), ...\array_slice($unknown, 600), (string) $directory->id('jsmith')];
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        $queries->reset();

        $found = self::store()->findMany(self::mappings()->get('User'), $ids);

        self::assertSame(['bjensen', 'alice', 'jsmith'], array_values(array_map(static fn (object $user): string => self::user($user)->getUserName(), $found)));
        self::assertSame([500, 500, 103], array_map(static fn (array $query): int => \count($query['params']), $queries->getData()['default']));
    }

    #[Test]
    #[TestDox('transactional() flushes insert(), update() and delete() and returns the value of the write')]
    public function writesInOneTransaction(): void
    {
        $directory = Directory::seed(self::createSchema());
        $store = self::store();
        $users = self::mappings()->get('User');
        $new = new User('newbie');

        $result = $store->transactional($users, static function () use ($store, $users, $new, $directory): User {
            $store->insert($users, $new);
            $jsmith = self::user($store->find($users, (string) $directory->id('jsmith')));
            $jsmith->setDisplayName('Johnny');

            $store->update($users, $jsmith);
            $store->delete($users, self::user($store->find($users, (string) $directory->id('alice'))));

            return $new;
        });
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();

        self::assertSame($new, $result);
        self::assertSame('newbie', self::user($store->find($users, (string) $new->getId()))->getUserName());
        self::assertSame('Johnny', self::user($store->find($users, (string) $directory->id('jsmith')))->getDisplayName());
        self::assertNull($store->find($users, (string) $directory->id('alice')));
    }

    #[Test]
    #[TestDox('A failing write stores nothing, rethrows, and leaves an open manager')]
    public function failingWriteStoresNothing(): void
    {
        self::createSchema();
        $store = self::store();
        $users = self::mappings()->get('User');
        $failure = new \RuntimeException('failed');
        $new = new User('newbie');

        try {
            $store->transactional($users, static function () use ($store, $users, $new, $failure): never {
                $store->insert($users, $new);
                self::getContainer()->get('doctrine.orm.entity_manager')->flush();

                throw $failure;
            });
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        self::assertTrue(self::getContainer()->get('doctrine.orm.entity_manager')->isOpen());
        self::assertNull($store->find($users, (string) $new->getId()));
    }

    #[Test]
    #[TestDox('A failing write keeps the SQL filters that were enabled at runtime, with their parameters')]
    public function failingWriteKeepsRuntimeFilters(): void
    {
        $directory = Directory::seed(self::createSchema());
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->getConfiguration()->addFilter('hidden_user', HiddenUserFilter::class);
        $parameters = (string) $entityManager->getFilters()->enable('hidden_user')->setParameter('userName', 'bjensen')->setParameterList('unused', ['a', 'b']);
        $store = self::store();
        $users = self::mappings()->get('User');
        $failure = new \RuntimeException('failed');

        try {
            $store->transactional($users, static fn (): never => throw $failure);
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        $filters = self::getContainer()->get('doctrine.orm.entity_manager')->getFilters()->getEnabledFilters();
        self::assertSame(['hidden_user' => $parameters], array_map(static fn (SQLFilter $filter): string => (string) $filter, $filters));
        self::assertNull($store->find($users, (string) $directory->id('bjensen')));
    }

    #[Test]
    #[TestDox('A unique violation is 409 uniqueness, and the manager is open afterwards')]
    public function uniqueViolationIsUniqueness(): void
    {
        Directory::seed(self::createSchema());
        $store = self::store();
        $users = self::mappings()->get('User');

        try {
            $store->transactional($users, static fn () => $store->insert($users, new User('bjensen')));
            self::fail('No exception.');
        } catch (ScimException $scimException) {
            self::assertSame([409, ScimErrorType::Uniqueness, 'One or more of the attribute values are already in use or are reserved.'], [$scimException->status, $scimException->scimType, $scimException->detail]);
            self::assertInstanceOf(UniqueConstraintViolationException::class, $scimException->getPrevious());
        }

        self::assertTrue(self::getContainer()->get('doctrine.orm.entity_manager')->isOpen());
        self::assertSame(4, $store->query($users, new ObjectQuery(null, [], SortOrder::Ascending, 1, 10))->totalResults);
    }

    #[Test]
    #[TestDox('A foreign key violation is 409')]
    public function foreignKeyViolationIsConflict(): void
    {
        $entityManager = self::createSchema();
        $connection = $entityManager->getConnection();
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }

        $store = self::store();

        $this->expectExceptionObject(ScimException::conflict('The resource is referenced by another resource, or refers to one that does not exist.'));

        $store->transactional(self::mappings()->get('Group'), static fn (): int|string => $connection->executeStatement('INSERT INTO app_group_user (group_id, user_id) VALUES (?, ?)', [Uuid::v7(), Uuid::v7()], [UuidType::NAME, UuidType::NAME]));
    }

    #[Test]
    #[TestDox('A class that Doctrine ORM does not manage fails with a fix')]
    public function unmanagedClassFails(): void
    {
        $this->expectExceptionObject(MappingException::inClass(Device::class, 'Doctrine ORM does not manage the class. Map it with #[ORM\Entity], or set #[ScimResource(store: ...)].'));

        self::store()->find(new AttributeLoader()->load(Device::class), 'd1');
    }

    #[Test]
    #[TestDox('The bundle registers the store as tacticmedia_scim_server.object_store.orm')]
    public function bundleRegistersTheStore(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['DoctrineBundle' => DoctrineBundle::class]);
        new TacticMediaScimServerBundle()->getContainerExtension()?->load([], $container);
        $definition = $container->getDefinition('tacticmedia_scim_server.object_store.orm');

        self::assertSame(OrmObjectStore::class, $definition->getClass());
        self::assertEquals([new Reference('doctrine'), new Reference('tacticmedia_scim_server.mapping_registry'), new Reference('tacticmedia_scim_server.converter_locator')], $definition->getArguments());
    }

    private static function user(?object $object): User
    {
        self::assertInstanceOf(User::class, $object);

        return $object;
    }
}
