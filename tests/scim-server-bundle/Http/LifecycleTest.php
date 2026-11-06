<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServer\Event\ResourceCreated;
use TacticMedia\ScimServer\Event\ResourceDeleted;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\EventListener\ProvisioningRecorder;

/**
 * Users and groups through HTTP, security, the library, the bundle mapping and Doctrine.
 */
final class LifecycleTest extends WebTestCase
{
    use ScimClient;

    #[Test]
    #[TestDox('A user goes through POST, GET, PUT, PATCH and DELETE, with an event per write')]
    public function userLifecycle(): void
    {
        $created = $this->send('POST', '/scim/v2/Users', [
            'schemas' => [Scim::USER, Scim::ENTERPRISE],
            'userName' => 'bjensen',
            'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen'],
            'userType' => 'employee',
            'emails' => [['value' => 'bjensen@example.com', 'type' => 'work']],
            'password' => 't1meMa$heen',
            Scim::ENTERPRISE => ['department' => 'Tour Operations'],
        ]);
        $id = self::idOf($created);
        $location = Scim::BASE_URL.'/Users/'.$id;

        self::assertSame([201, $location], [$created->getStatusCode(), $created->headers->get('Location')]);
        self::assertSame([
            'schemas' => [Scim::USER, Scim::ENTERPRISE],
            'id' => $id,
            'meta' => ['resourceType' => 'User', 'created' => '2026-11-06T10:00:00Z', 'lastModified' => '2026-11-06T10:00:00Z', 'version' => $created->headers->get('ETag'), 'location' => $location],
            'userName' => 'bjensen',
            'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen', 'formatted' => 'Barbara Jensen'],
            'userType' => 'Employee',
            'active' => true,
            'emails' => [['value' => 'bjensen@example.com', 'type' => 'work', 'primary' => true]],
            Scim::ENTERPRISE => ['department' => 'Tour Operations'],
        ], self::json($created));
        self::assertTrue(password_verify('t1meMa$heen', (string) $this->user($id)->getPassword()));
        self::assertSame(self::json($created), self::json($this->send('GET', '/scim/v2/Users/'.$id)));

        $replaced = $this->send('PUT', '/scim/v2/Users/'.$id, ['schemas' => [Scim::USER], 'userName' => 'bjensen', 'active' => false]);
        self::assertSame([200, false, null], [$replaced->getStatusCode(), self::json($replaced)['active'] ?? null, self::json($replaced)['name'] ?? null]);
        self::assertTrue(password_verify('t1meMa$heen', (string) $this->user($id)->getPassword()));

        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp(['op' => 'add', 'path' => 'nickName', 'value' => 'Babs'])));
        self::assertSame('Babs', self::json($this->send('GET', '/scim/v2/Users/'.$id))['nickName'] ?? null);

        self::assertNoContent($this->send('DELETE', '/scim/v2/Users/'.$id));
        self::assertScimError($this->send('GET', '/scim/v2/Users/'.$id), 404, null, 'Resource '.$id.' not found.');
        self::assertSame([ResourceCreated::class, ResourceUpdated::class, ResourceUpdated::class, ResourceDeleted::class], array_map(get_class(...), self::getContainer()->get(ProvisioningRecorder::class)->events));
    }

    #[Test]
    #[TestDox('A group goes through POST with members, member PATCHes, nested groups and DELETE')]
    public function groupLifecycle(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $staff = self::idOf($this->send('POST', '/scim/v2/Groups', ['schemas' => [Scim::GROUP], 'displayName' => 'Staff']));

        $created = $this->send('POST', '/scim/v2/Groups', ['schemas' => [Scim::GROUP], 'displayName' => 'Tour Guides', 'members' => [['value' => $alice], ['value' => $staff, 'type' => 'Group']]]);
        $id = self::idOf($created);
        self::assertSame([
            ['value' => $alice, 'display' => 'alice', 'type' => 'User', '$ref' => Scim::BASE_URL.'/Users/'.$alice],
            ['value' => $staff, 'display' => 'Staff', 'type' => 'Group', '$ref' => Scim::BASE_URL.'/Groups/'.$staff],
        ], self::json($created)['members'] ?? null);

        self::assertNoContent($this->send('PATCH', '/scim/v2/Groups/'.$id, self::patchOp(
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => $bob]]],
            ['op' => 'remove', 'path' => 'members[value eq "'.$alice.'"]'],
        )));

        self::assertSame([$bob, $staff], array_column((array) (self::json($this->send('GET', '/scim/v2/Groups/'.$id))['members'] ?? []), 'value'));
        self::assertSame([['value' => $id, 'display' => 'Tour Guides', '$ref' => Scim::BASE_URL.'/Groups/'.$id]], self::json($this->send('GET', '/scim/v2/Users/'.$bob))['groups'] ?? null);
        self::assertSame([$id], self::ids($this->send('GET', '/scim/v2/Groups?filter='.rawurlencode('members[type eq "Group" and value eq "'.$staff.'"]'))));

        self::assertNoContent($this->send('DELETE', '/scim/v2/Groups/'.$id));
        self::assertNull($this->group($id));
        self::assertSame([$staff], self::ids($this->send('GET', '/scim/v2/Groups')));
        self::assertSame([], self::json($this->send('GET', '/scim/v2/Users/'.$bob))['groups'] ?? []);
    }

    #[Test]
    #[TestDox('An unmapped attribute answers 400 in a filter and in a PATCH path, and is dropped from a body')]
    public function unmappedAttributesAreRejected(): void
    {
        $id = $this->createUser('bjensen', ['locale' => 'en-US']);

        self::assertArrayNotHasKey('locale', self::json($this->send('GET', '/scim/v2/Users/'.$id)));
        self::assertScimError($this->send('GET', '/scim/v2/Users?filter='.rawurlencode('locale eq "en"')), 400, 'invalidFilter', "The attribute 'locale' is not defined for the resource type User.");
        self::assertScimError($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp(['op' => 'add', 'path' => 'locale', 'value' => 'en-US'])), 400, 'invalidPath', "Operations[0]: The attribute 'locale' is not defined for the resource type User.");
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createUser(string $userName, array $attributes = []): string
    {
        $response = $this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => $userName, ...$attributes]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return self::idOf($response);
    }

    private function user(string $id): User
    {
        $user = self::getContainer()->get('doctrine')->getManager()->find(User::class, Uuid::fromString($id));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function group(string $id): ?Group
    {
        return self::getContainer()->get('doctrine')->getManager()->find(Group::class, Uuid::fromString($id));
    }
}
