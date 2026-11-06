<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Fan;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Gang;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Player;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Rookie;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Squad;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;

/**
 * Player has #[ScimSoftDelete] with the default revive: false on a bool and a unique userName column. Squad takes the
 * delta path. Fan has revive: true, and Gang takes the full PATCH path.
 */
final class SoftDeleteRepositoryTest extends KernelTestCase
{
    use CreatesSchema;
    use DeltaServer;
    use OrmRepositories;
    private const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    private EntityManagerInterface $entityManager;

    private ScimServer $server;

    #[\Override]
    protected function setUp(): void
    {
        $this->entityManager = self::createSchema();
        $this->server = self::ormServer(self::mappings(['User' => Player::class, 'Group' => Squad::class]));
    }

    #[Test]
    #[TestDox('By default, DELETE sets the flag and a new user with the same userName meets the unique index: 409')]
    public function withoutReviveCreatesAnew(): void
    {
        $id = $this->json(self::request($this->server, 'POST', '/Users', ['userName' => 'alice']))['id'] ?? null;
        self::assertIsString($id);

        self::assertSame(204, self::request($this->server, 'DELETE', '/Users/'.$id)->getStatusCode());
        $again = self::request($this->server, 'POST', '/Users', ['userName' => 'alice']);

        self::assertSame([409, 'uniqueness'], [$again->getStatusCode(), $this->json($again)['scimType'] ?? null]);
        self::assertSame([true], array_map(static fn (Player $player): bool => $player->deleted, $this->entityManager->getRepository(Player::class)->findAll()));
    }

    #[Test]
    #[TestDox('A partial unique index on the live rows lets a new user take the userName of a soft-deleted one')]
    public function partialIndexStoresNewUser(): void
    {
        $this->liveUserNameIndex();
        $server = self::ormServer(self::mappings(['User' => Rookie::class]));
        $old = $this->json(self::request($server, 'POST', '/Users', ['userName' => 'alice']))['id'] ?? null;
        self::assertIsString($old);
        self::assertSame(204, self::request($server, 'DELETE', '/Users/'.$old)->getStatusCode());

        $new = self::request($server, 'POST', '/Users', ['userName' => 'alice']);

        self::assertSame(201, $new->getStatusCode(), (string) $new->getContent());
        self::assertNotSame($old, $this->json($new)['id'] ?? null);
        $this->entityManager->persist(new Rookie('alice'));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    #[Test]
    #[TestDox('revive: true removes the user from groups without member deltas and empties its readOnly groups')]
    public function reviveUnlinksWithoutDeltas(): void
    {
        [$alice, $bob] = [new Fan('alice'), new Fan('bob')];
        [$red, $blue] = [new Gang('Red'), new Gang('Blue')];
        $red->addFan($alice);
        $red->addFan($bob);

        $alice->addGang($blue);
        array_map($this->entityManager->persist(...), [$alice, $bob, $red, $blue]);
        $alice->deleted = true;
        $this->entityManager->flush();

        $revived = self::request(self::ormServer(self::mappings(['User' => Fan::class, 'Group' => Gang::class])), 'POST', '/Users', ['userName' => 'alice']);

        self::assertSame([201, 'f'.$alice->id, false], [$revived->getStatusCode(), $this->json($revived)['id'] ?? null, \array_key_exists('groups', $this->json($revived))]);
        $this->entityManager->clear();
        $stored = $this->entityManager->find(Gang::class, $red->id);
        self::assertInstanceOf(Gang::class, $stored);
        self::assertSame([['bob'], 2], [$stored->getFans()->map(static fn (Fan $fan): string => $fan->userName)->getValues(), $stored->version]);
        self::assertCount(0, $this->entityManager->find(Fan::class, $alice->id)?->getGangs() ?? []);
    }

    #[Test]
    #[TestDox('A delta PATCH keeps the link to a soft-deleted member and rejects its id')]
    public function deltaKeepsDeletedMembers(): void
    {
        [$alice, $bob, $carol] = array_map(static fn (string $name): Player => new Player($name), ['alice', 'bob', 'carol']);
        $red = new Squad('Red');
        $red->addPlayer($alice);
        $red->addPlayer($bob);
        array_map($this->entityManager->persist(...), [$alice, $bob, $carol, $red]);
        $alice->deleted = true;
        $this->entityManager->flush();

        $replace = self::request($this->server, 'PATCH', '/Groups/s'.$red->id, $this->patch('replace', [['value' => 'p'.$carol->id]]));
        $add = self::request($this->server, 'PATCH', '/Groups/s'.$red->id, $this->patch('add', [['value' => 'p'.$alice->id]]));

        self::assertSame(204, $replace->getStatusCode(), (string) $replace->getContent());
        self::assertSame([400, 'invalidValue'], [$add->getStatusCode(), $this->json($add)['scimType'] ?? null]);
        self::assertEqualsCanonicalizing([$alice->id, $carol->id], $this->entityManager->getConnection()->fetchFirstColumn('SELECT player_id FROM delta_squad_player'));
        $members = $this->json(self::request($this->server, 'GET', '/Groups/s'.$red->id))['members'] ?? null;
        self::assertIsArray($members);
        self::assertSame(['p'.$carol->id], array_column($members, 'value'));
    }

    /**
     * @param list<array<string, string>> $members
     *
     * @return array<string, mixed>
     */
    private function patch(string $op, array $members): array
    {
        return ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => $op, 'path' => 'members', 'value' => $members]]];
    }

    /**
     * The index of the guide for each platform. DBAL creates it from the mapping only on PostgreSQL.
     */
    private function liveUserNameIndex(): void
    {
        $connection = $this->entityManager->getConnection();
        $statements = match (true) {
            $connection->getDatabasePlatform() instanceof SQLitePlatform => ['DROP INDEX delta_rookie_live_user_name', 'CREATE UNIQUE INDEX delta_rookie_live_user_name ON delta_rookie (user_name) WHERE deleted_at IS NULL'],
            $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform => ['ALTER TABLE delta_rookie DROP INDEX delta_rookie_live_user_name, ADD live_user_name VARCHAR(100) GENERATED ALWAYS AS (IF(deleted_at IS NULL, user_name, NULL)) VIRTUAL, ADD UNIQUE INDEX delta_rookie_live_user_name (live_user_name)'],
            default => [],
        };

        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'deltas'] + $options);
    }
}
