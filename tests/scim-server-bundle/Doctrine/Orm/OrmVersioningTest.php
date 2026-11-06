<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Badge;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

/**
 * The version statement of OrmObjectStore::update() and delete(). A DBAL statement plays the concurrent request.
 */
final class OrmVersioningTest extends KernelTestCase
{
    use CreatesOrmStore;
    private const string CHANGED = 'Failed to update. Resource has changed on the server.';

    private Directory $directory;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        $entityManager = self::createSchema();
        $this->directory = Directory::seed($entityManager);
        $this->connection = $entityManager->getConnection();
    }

    #[Test]
    #[TestDox('update() with a version that the row no longer has is 412 and writes nothing')]
    public function staleUpdateFails(): void
    {
        $store = self::store();
        $users = self::mappings()->get('User');
        $jsmith = $this->user('jsmith');
        $this->connection->executeStatement("UPDATE app_user SET version = version + 1, displayName = 'Other' WHERE userName = 'jsmith'");

        $this->assertScimError(412, self::CHANGED, static function () use ($store, $users, $jsmith): void {
            $store->transactional($users, static function () use ($store, $users, $jsmith): void {
                $jsmith->setDisplayName('Johnny');
                $store->update($users, $jsmith, 1);
            });
        });
        self::assertSame(['Other', 2], $this->row('app_user', 'displayName', 'userName', 'jsmith'));
    }

    #[Test]
    #[TestDox('update() increments the version for a member change, after a concurrent write, and for a column change')]
    public function updateIncrementsTheVersion(): void
    {
        $store = self::store();
        $groups = self::mappings()->get('Group');
        $staff = $this->group('Staff');
        $alice = $this->user('alice');
        $this->connection->executeStatement("UPDATE app_group SET version = version + 1 WHERE name = 'Staff'");

        $store->transactional($groups, static function () use ($store, $groups, $staff, $alice): void {
            $staff->addUser($alice);
            $store->update($groups, $staff);
        });
        $memberChange = [$staff->getVersion(), $this->row('app_group', 'name', 'name', 'Staff')[1]];
        $store->transactional($groups, static function () use ($store, $groups, $staff): void {
            $staff->setName('Crew');
            $store->update($groups, $staff, 3);
        });

        self::assertSame([3, 3], $memberChange);
        self::assertSame([5, ['Crew', 5]], [$staff->getVersion(), $this->row('app_group', 'name', 'name', 'Crew')]);
    }

    #[Test]
    #[TestDox('An update() without a version of a row that another request deleted is 404')]
    public function updateOfDeletedRowFails(): void
    {
        $store = self::store();
        $users = self::mappings()->get('User');
        $omalley = $this->user('omalley');
        $this->connection->executeStatement("DELETE FROM app_user WHERE userName = 'omalley'");

        $this->assertScimError(404, 'The resource was deleted by another request.', static fn () => $store->transactional($users, static fn () => $store->update($users, $omalley)));
    }

    #[Test]
    #[TestDox('delete() with a version that the row no longer has is 412 and keeps the row; with its version it deletes')]
    public function conditionalDelete(): void
    {
        $store = self::store();
        $groups = self::mappings()->get('Group');
        $this->connection->executeStatement("UPDATE app_group SET version = version + 1 WHERE name = 'Empty'");
        $empty = $this->group('Empty');

        $this->assertScimError(412, self::CHANGED, static fn () => $store->transactional($groups, static fn () => $store->delete($groups, $empty, 1)));
        self::assertSame(['Empty', 2], $this->row('app_group', 'name', 'name', 'Empty'));

        $empty = $this->group('Empty');
        $store->transactional($groups, static fn () => $store->delete($groups, $empty, 2));
        self::assertFalse($this->connection->fetchOne("SELECT name FROM app_group WHERE name = 'Empty'"));
    }

    #[Test]
    #[TestDox('A class without #[ORM\\Version] is written without a version statement')]
    public function unversionedClassHasNoStatement(): void
    {
        $store = self::store();
        $badges = self::mappings()->get('Badge');
        $badge = new Badge('SN-1');
        $store->transactional($badges, static fn () => $store->insert($badges, $badge));
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        $queries->reset();

        $store->transactional($badges, static function () use ($store, $badges, $badge): void {
            $badge->setSerialNumber('SN-2');
            $store->update($badges, $badge);
        });
        $store->transactional($badges, static fn () => $store->delete($badges, $badge, 7));

        $statements = implode("\n", array_column($queries->getData()['default'], 'sql'));

        self::assertStringContainsString('UPDATE app_badge SET serialNumber = ? WHERE id = ?', $statements);
        self::assertStringContainsString('DELETE FROM app_badge WHERE id = ?', $statements);
        self::assertStringNotContainsStringIgnoringCase('version', $statements);
    }

    #[Test]
    #[TestDox('An OptimisticLockException is 409')]
    public function optimisticLockIsConflict(): void
    {
        $this->expectExceptionObject(ScimException::conflict('The resource changed during the request. Retry the request.'));

        self::store()->transactional(self::mappings()->get('User'), static fn (): never => throw OptimisticLockException::lockFailed(new User('bjensen')));
    }

    private function user(string $userName): User
    {
        $user = self::store()->find(self::mappings()->get('User'), (string) $this->directory->id($userName));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function group(string $name): Group
    {
        $group = self::store()->find(self::mappings()->get('Group'), (string) $this->directory->id($name));
        self::assertInstanceOf(Group::class, $group);

        return $group;
    }

    /**
     * @return array{mixed, int}
     */
    private function row(string $table, string $column, string $keyColumn, string $key): array
    {
        $row = $this->connection->fetchNumeric(\sprintf('SELECT %s, version FROM %s WHERE %s = ?', $column, $table, $keyColumn), [$key]);
        self::assertIsArray($row);

        return [$row[0], (int) $row[1]];
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function assertScimError(int $status, string $detail, \Closure $call): void
    {
        try {
            $call();
        } catch (ScimException $scimException) {
            self::assertSame([$status, $detail], [$scimException->status, $scimException->detail]);

            return;
        }

        self::fail('No ScimException.');
    }
}
