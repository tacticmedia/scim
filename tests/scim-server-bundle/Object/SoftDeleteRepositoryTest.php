<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Player;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Squad;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;

/**
 * Player has #[ScimSoftDelete(revive: false)] on a bool and a unique userName column. Squad takes the delta path.
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
    #[TestDox('With revive: false, DELETE sets the flag and a new user with the same userName meets the unique index: 409')]
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
