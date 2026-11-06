<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

/**
 * The fixture User has #[ScimSoftDelete(revive: true)] on $deletedAt. The Group repository of the test kernel takes
 * the full PATCH path.
 */
final class SoftDeleteTest extends WebTestCase
{
    use ScimClient;

    #[Test]
    #[TestDox('DELETE stamps the row with the clock time; GET, DELETE, filters and the count then skip the user')]
    public function deleteKeepsTheRow(): void
    {
        $id = $this->user('bjensen');

        self::assertNoContent($this->send('DELETE', '/scim/v2/Users/'.$id));

        self::assertSame('2026-11-06 10:00:00', $this->stored(User::class, $id)->deletedAt?->format('Y-m-d H:i:s'));
        self::assertScimError($this->send('GET', '/scim/v2/Users/'.$id), 404, null, 'Resource '.$id.' not found.');
        self::assertScimError($this->send('DELETE', '/scim/v2/Users/'.$id), 404, null, 'Resource '.$id.' not found.');
        self::assertSame([], self::ids($this->send('GET', '/scim/v2/Users?filter=userName eq "bjensen"')));
        self::assertSame(0, self::json($this->send('GET', '/scim/v2/Users'))['totalResults'] ?? null);
    }

    #[Test]
    #[TestDox('A soft-deleted user is not a group member and not a manager')]
    public function deletedUserIsNoReference(): void
    {
        $bjensen = $this->user('bjensen');
        $jsmith = $this->user('jsmith', [Scim::ENTERPRISE => ['manager' => ['value' => $bjensen]]]);
        $group = $this->group([$bjensen, $jsmith]);

        self::assertNoContent($this->send('DELETE', '/scim/v2/Users/'.$bjensen));

        self::assertArrayNotHasKey(Scim::ENTERPRISE, self::json($this->send('GET', '/scim/v2/Users/'.$jsmith)));
        self::assertSame([$jsmith], $this->members($group));
        self::assertSame([], self::ids($this->send('GET', '/scim/v2/Groups?filter=members.value eq "'.$bjensen.'"')));
        self::assertSame([], self::ids($this->send('GET', '/scim/v2/Users?filter=manager.value eq "'.$bjensen.'"')));
    }

    #[Test]
    #[TestDox('Writes keep the links to a soft-deleted user and reject its id')]
    public function writesKeepDeletedLinks(): void
    {
        $bjensen = $this->user('bjensen');
        $jsmith = $this->user('jsmith', [Scim::ENTERPRISE => ['manager' => ['value' => $bjensen]]]);
        $group = $this->group([$bjensen, $jsmith]);
        self::assertNoContent($this->send('DELETE', '/scim/v2/Users/'.$bjensen));
        $unknown = \sprintf('Attribute "members": "%s" is not the id of a User or Group.', $bjensen);

        self::assertSame(200, $this->send('PUT', '/scim/v2/Groups/'.$group, ['schemas' => [Scim::GROUP], 'displayName' => 'Staff', 'members' => [['value' => $jsmith]]])->getStatusCode());
        self::assertSame(200, $this->send('PUT', '/scim/v2/Users/'.$jsmith, ['schemas' => [Scim::USER], 'userName' => 'jsmith'])->getStatusCode());
        self::assertScimError($this->send('PATCH', '/scim/v2/Groups/'.$group, self::patchOp(['op' => 'add', 'path' => 'members', 'value' => [['value' => $bjensen]]])), 400, 'invalidValue', $unknown);
        self::assertSame(400, $this->send('PUT', '/scim/v2/Users/'.$jsmith, ['schemas' => [Scim::USER], 'userName' => 'jsmith', Scim::ENTERPRISE => ['manager' => ['value' => $bjensen]]])->getStatusCode());

        self::assertEqualsCanonicalizing(['bjensen', 'jsmith'], $this->stored(Group::class, $group)->getUsers()->map(static fn (User $user): string => $user->getUserName())->getValues());
        self::assertSame('bjensen', $this->stored(User::class, $jsmith)->getManager()?->getUserName());
    }

    #[Test]
    #[TestDox('revive: true: a POST with the userName of a soft-deleted user gets 201, its id and only the new values')]
    public function createRevives(): void
    {
        $carol = $this->user('carol');
        $bjensen = $this->user('bjensen', ['displayName' => 'Old', 'title' => 'Tour Guide', Scim::ENTERPRISE => ['manager' => ['value' => $carol]]]);
        self::assertNoContent($this->send('DELETE', '/scim/v2/Users/'.$carol));
        self::assertNoContent($this->send('DELETE', '/scim/v2/Users/'.$bjensen));

        $revived = $this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'BJensen', 'displayName' => 'New']);

        self::assertSame(201, $revived->getStatusCode(), (string) $revived->getContent());
        self::assertSame([$bjensen, 'BJensen', 'New', null], [self::idOf($revived), self::json($revived)['userName'] ?? null, self::json($revived)['displayName'] ?? null, self::json($revived)['title'] ?? null]);
        $stored = $this->stored(User::class, $bjensen);
        self::assertSame([null, null], [$stored->deletedAt, $stored->getManager()]);
    }

    #[Test]
    #[TestDox('A revive without password clears the stored password')]
    public function reviveClearsPassword(): void
    {
        $bjensen = $this->user('bjensen', ['password' => 'OldPersonSecret1!']);
        self::assertNoContent($this->send('DELETE', '/scim/v2/Users/'.$bjensen));

        self::assertSame(201, $this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen'])->getStatusCode());

        self::assertNull($this->stored(User::class, $bjensen)->getPassword());
    }

    #[Test]
    #[TestDox('A revive removes the user from its groups and changes their meta.version')]
    public function reviveLeavesGroups(): void
    {
        $bjensen = $this->user('bjensen');
        $jsmith = $this->user('jsmith');
        $group = $this->group([$bjensen, $jsmith]);
        $etag = $this->send('GET', '/scim/v2/Groups/'.$group)->headers->get('ETag');
        self::assertNoContent($this->send('DELETE', '/scim/v2/Users/'.$bjensen));

        $revived = $this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen']);

        self::assertArrayNotHasKey('groups', self::json($revived));
        self::assertSame([$jsmith], $this->members($group));
        self::assertNotSame($etag, $this->send('GET', '/scim/v2/Groups/'.$group)->headers->get('ETag'));
        self::assertSame(['jsmith'], $this->stored(Group::class, $group)->getUsers()->map(static fn (User $user): string => $user->getUserName())->getValues());
    }

    #[Test]
    #[TestDox('A revive clears the manager of the users that it managed')]
    public function reviveClearsManagerReferences(): void
    {
        $bjensen = $this->user('bjensen');
        $jsmith = $this->user('jsmith', [Scim::ENTERPRISE => ['manager' => ['value' => $bjensen]]]);
        self::assertNoContent($this->send('DELETE', '/scim/v2/Users/'.$bjensen));

        self::assertSame(201, $this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen'])->getStatusCode());

        self::assertArrayNotHasKey(Scim::ENTERPRISE, self::json($this->send('GET', '/scim/v2/Users/'.$jsmith)));
        self::assertNull($this->stored(User::class, $jsmith)->getManager());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function user(string $userName, array $attributes = []): string
    {
        return self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER, Scim::ENTERPRISE], 'userName' => $userName, ...$attributes]));
    }

    /**
     * @param list<string> $members
     */
    private function group(array $members): string
    {
        return self::idOf($this->send('POST', '/scim/v2/Groups', ['schemas' => [Scim::GROUP], 'displayName' => 'Staff', 'members' => array_map(static fn (string $id): array => ['value' => $id], $members)]));
    }

    /**
     * @return list<mixed>
     */
    private function members(string $group): array
    {
        $members = self::json($this->send('GET', '/scim/v2/Groups/'.$group))['members'] ?? [];
        self::assertIsArray($members);

        return array_column($members, 'value');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function stored(string $class, string $id): object
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $object = $entityManager->find($class, Uuid::fromString($id));
        self::assertInstanceOf($class, $object);

        return $object;
    }
}
