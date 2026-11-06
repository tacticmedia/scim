<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Object\DeltaServer;

/**
 * Circular bulkId references (RFC 7644 section 3.7.1) through the Doctrine store.
 */
final class BulkCycleTest extends WebTestCase
{
    use DeltaServer;
    use ScimClient;
    private const string BULK_REQUEST = 'urn:ietf:params:scim:api:messages:2.0:BulkRequest';

    #[Test]
    #[TestDox('RFC 7644 3.7.1 example: each group has the other as member')]
    public function resolvesGroupCycle(): void
    {
        $response = $this->send('POST', '/scim/v2/Bulk', $this->groupCycle());

        self::assertSame(['201', '201'], array_column($this->operations($response), 'status'));
        $this->assertMutualSubgroups();
    }

    #[Test]
    #[TestDox('RFC 7644 3.7.1 example through the delta path: each group has the other as member')]
    public function resolvesGroupCycleByDelta(): void
    {
        $response = self::request($this->serverOf(self::deltaGroups()), 'POST', '/Bulk', $this->groupCycle());

        self::assertSame(['201', '201'], array_column($this->operations($response), 'status'));
        $this->assertMutualSubgroups();
    }

    #[Test]
    #[TestDox('A manager cycle between two users: each user is the manager of the other')]
    public function resolvesManagerCycle(): void
    {
        $response = $this->send('POST', '/scim/v2/Bulk', ['schemas' => [self::BULK_REQUEST], 'Operations' => [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['schemas' => [Scim::USER, Scim::ENTERPRISE], 'userName' => 'alice', Scim::ENTERPRISE => ['manager' => ['value' => 'bulkId:b']]]],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'b', 'data' => ['schemas' => [Scim::USER, Scim::ENTERPRISE], 'userName' => 'bob', Scim::ENTERPRISE => ['manager' => ['value' => 'bulkId:a']]]],
        ]]);

        self::assertSame(['201', '201'], array_column($this->operations($response), 'status'));
        $users = $this->entityManager()->getRepository(User::class);
        $alice = $users->findOneBy(['userName' => 'alice']);
        $bob = $users->findOneBy(['userName' => 'bob']);
        self::assertSame(['bob', 'alice'], [$alice?->getManager()?->getUserName(), $bob?->getManager()?->getUserName()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function groupCycle(): array
    {
        return ['schemas' => [self::BULK_REQUEST], 'Operations' => [
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'qwerty', 'data' => ['schemas' => [Scim::GROUP], 'displayName' => 'Group A', 'members' => [['type' => 'Group', 'value' => 'bulkId:ytrewq']]]],
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'ytrewq', 'data' => ['schemas' => [Scim::GROUP], 'displayName' => 'Group B', 'members' => [['type' => 'Group', 'value' => 'bulkId:qwerty']]]],
        ]];
    }

    private function assertMutualSubgroups(): void
    {
        $groups = $this->entityManager()->getRepository(Group::class);
        $subgroups = array_map(static fn (?Group $group): array => $group?->getSubgroups()->map(static fn (Group $subgroup): string => $subgroup->getName())->getValues() ?? [], [$groups->findOneBy(['name' => 'Group A']), $groups->findOneBy(['name' => 'Group B'])]);

        self::assertSame([['Group B'], ['Group A']], $subgroups);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->clear();

        return $entityManager;
    }

    /**
     * @return list<array<mixed>>
     */
    private function operations(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertIsArray($body);
        self::assertIsList($body['Operations'] ?? null);

        return array_values(array_filter($body['Operations'], \is_array(...)));
    }
}
