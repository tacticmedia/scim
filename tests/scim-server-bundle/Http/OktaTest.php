<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\EventListener\ProvisioningRecorder;

/**
 * Okta, with the bare token of Okta "Header" authentication and the request bodies of
 * tests/scim-server/Conformance/OktaTest.php. Bodies carry Content-Type: application/scim+json, because
 * Request::create() adds application/x-www-form-urlencoded to a POST or PUT without one.
 */
final class OktaTest extends WebTestCase
{
    use ScimClient;
    private const array SPEC_TEST_POST = ['User-Agent' => 'OKTA SCIM Integration', 'Authorization' => 'Bearer okta-secret-token', 'Content-Type' => 'application/json', 'Accept' => 'application/scim+json; charset=utf-8'];
    private const array SPEC_TEST_GET = ['User-Agent' => 'OKTA SCIM Integration', 'Authorization' => 'Bearer okta-secret-token', 'Content-Type' => 'application/scim+json; charset=utf-8', 'Accept' => 'application/scim+json', 'Accept-Charset' => 'utf-8'];

    #[Test]
    #[TestDox('1.4.1 Provisioning enabled: GET /Users?startIndex=1&count=2 and GET /Groups?startIndex=1&count=100 answer ListResponses')]
    public function answersFirstRequests(): void
    {
        $this->oktaUsers('test.user@okta.local', 'babs@okta.local', 'jsmith@okta.local');
        $this->okta('POST', '/scim/v2/Groups', ['schemas' => [Scim::GROUP], 'displayName' => 'Test SCIMv2']);

        $users = self::json($this->okta('GET', '/scim/v2/Users?startIndex=1&count=2'));
        $groups = self::json($this->okta('GET', '/scim/v2/Groups?startIndex=1&count=100'));

        self::assertSame([Scim::LIST_RESPONSE, 3, 2, 1], [$users['schemas'][0] ?? null, $users['totalResults'] ?? null, $users['itemsPerPage'] ?? null, $users['startIndex'] ?? null]);
        self::assertSame([Scim::LIST_RESPONSE, 1, 1, 1], [$groups['schemas'][0] ?? null, $groups['totalResults'] ?? null, $groups['itemsPerPage'] ?? null, $groups['startIndex'] ?? null]);
    }

    #[Test]
    #[TestDox('1.4.2 User assignment: the encoded userName filter finds nothing, then the user in any case')]
    public function looksUpUserNames(): void
    {
        self::assertSame([], self::ids($this->okta('GET', '/scim/v2/Users?filter=userName%20eq%20%22test.user%40okta.local%22&startIndex=1&count=100')));
        [$id] = $this->oktaUsers('test.user@okta.local');

        self::assertSame([$id], self::ids($this->okta('GET', '/scim/v2/Users?filter=userName%20eq%20%22TEST.USER%40OKTA.LOCAL%22&startIndex=1&count=100')));
        self::assertSame([$id], self::ids($this->send('GET', '/scim/v2/Users?filter=userName eq "TEST.USER@OKTA.LOCAL"', headers: self::SPEC_TEST_GET)));
    }

    #[Test]
    #[TestDox('1.4.3 Import users: pages of 100 with startIndex+100, a stable order and inactive users')]
    public function importsUsers(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $ids = [];

        foreach (range(1, 150) as $i) {
            $user = new User(\sprintf('user%03d@okta.local', $i));
            $user->setEnabled(150 !== $i);
            $entityManager->persist($user);
            $ids[] = (string) $user->getId();
        }

        $entityManager->flush();
        $first = self::json($this->okta('GET', '/scim/v2/Users?startIndex=1&count=100'));
        $second = self::json($this->okta('GET', '/scim/v2/Users?startIndex=101&count=100'));

        self::assertSame([150, 100, 1], [$first['totalResults'] ?? null, $first['itemsPerPage'] ?? null, $first['startIndex'] ?? null]);
        self::assertSame([150, 50, 101], [$second['totalResults'] ?? null, $second['itemsPerPage'] ?? null, $second['startIndex'] ?? null]);
        self::assertSame($ids, [...array_column((array) ($first['Resources'] ?? []), 'id'), ...array_column((array) ($second['Resources'] ?? []), 'id')]);
        self::assertSame($first['Resources'] ?? null, self::json($this->okta('GET', '/scim/v2/Users?startIndex=1&count=100'))['Resources'] ?? null);
        self::assertFalse($second['Resources'][49]['active'] ?? null);
    }

    #[Test]
    #[TestDox('1.4.4 Create user: POST with groups [] and password answers 201 with the user, without the password, and stores its hash')]
    public function createsUser(): void
    {
        $created = $this->okta('POST', '/scim/v2/Users', [
            'schemas' => [Scim::USER],
            'userName' => 'test.user@okta.local',
            'name' => ['givenName' => 'Test', 'familyName' => 'User'],
            'emails' => [['primary' => true, 'value' => 'test.user@okta.local', 'type' => 'work']],
            'displayName' => 'Test User',
            'locale' => 'en-US',
            'externalId' => '00ujl29u0le5T6Aj10h7',
            'groups' => [],
            'password' => '1mz050nq',
            'active' => true,
        ]);

        $id = self::idOf($created);
        self::assertSame([201, Scim::BASE_URL.'/Users/'.$id], [$created->getStatusCode(), $created->headers->get('Location')]);
        self::assertSame([
            'schemas' => [Scim::USER],
            'id' => $id,
            'externalId' => '00ujl29u0le5T6Aj10h7',
            'meta' => ['resourceType' => 'User', 'created' => '2026-11-06T10:00:00Z', 'lastModified' => '2026-11-06T10:00:00Z', 'version' => $created->headers->get('ETag'), 'location' => Scim::BASE_URL.'/Users/'.$id],
            'userName' => 'test.user@okta.local',
            'name' => ['givenName' => 'Test', 'familyName' => 'User', 'formatted' => 'Test User'],
            'displayName' => 'Test User',
            'active' => true,
            'emails' => [['value' => 'test.user@okta.local', 'type' => 'work', 'primary' => true]],
        ], self::json($created));
        self::assertTrue(password_verify('1mz050nq', (string) $this->user($id)->getPassword()));
    }

    #[Test]
    #[TestDox('1.4.4 SPEC test create with application/json answers 201; the same POST again answers 409 uniqueness')]
    public function createsSpecTestUser(): void
    {
        $body = ['schemas' => [Scim::USER], 'userName' => 'Ms.Beryl@example.com', 'name' => ['givenName' => 'Beryl', 'familyName' => 'Kassulke'], 'emails' => [['primary' => true, 'value' => 'beryl@example.com', 'type' => 'work']], 'displayName' => 'Beryl Kassulke', 'active' => true];

        $created = $this->send('POST', '/scim/v2/Users', $body, self::SPEC_TEST_POST);
        $user = self::json($created);

        self::assertSame([201, 'application/scim+json'], [$created->getStatusCode(), $created->headers->get('Content-Type')]);
        self::assertSame([true, 'Kassulke', 'Beryl', [Scim::USER], 'Ms.Beryl@example.com'], [$user['active'] ?? null, $user['name']['familyName'] ?? null, $user['name']['givenName'] ?? null, $user['schemas'] ?? null, $user['userName'] ?? null]);
        self::assertScimError($this->send('POST', '/scim/v2/Users', $body, self::SPEC_TEST_POST), 409, 'uniqueness', 'The value of attribute "userName" is already in use.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIds(): iterable
    {
        yield '010101001010101011001010101011' => ['010101001010101011001010101011'];
        yield '00919288221112222' => ['00919288221112222'];
        yield 'a UUID' => ['26118915-6090-4610-87e4-49d8ca9f808d'];
    }

    #[Test]
    #[DataProvider('unknownIds')]
    #[TestDox('1.4.5 SPEC test GET of the unknown id $_dataName answers 404 with an Error body')]
    public function answersUnknownId(string $id): void
    {
        $this->oktaUsers('test.user@okta.local');

        self::assertScimError($this->send('GET', '/scim/v2/Users/'.$id, headers: self::SPEC_TEST_GET), 404, null, 'Resource '.$id.' not found.');
    }

    #[Test]
    #[TestDox('1.4.6 Profile update: PUT of the GET output with id, meta and groups answers 200, keeps the password and clears the omitted nickName')]
    public function replacesUserFromGetOutput(): void
    {
        [$id] = $this->oktaUsers('test.user@okta.local');
        self::assertNoContent($this->okta('PATCH', '/scim/v2/Users/'.$id, self::patchOp(['op' => 'add', 'value' => ['nickName' => 'Testy', 'password' => '1mz050nq']])));
        $user = self::json($this->okta('GET', '/scim/v2/Users/'.$id));
        unset($user['nickName']);
        $user['name'] = ['givenName' => 'Another', 'middleName' => 'Excited', 'familyName' => 'User'];
        $user['groups'] = [];

        $replaced = $this->okta('PUT', '/scim/v2/Users/'.$id, $user);

        self::assertSame(200, $replaced->getStatusCode());
        self::assertNotSame($user['meta']['version'] ?? null, $replaced->headers->get('ETag'));
        self::assertSame(['givenName' => 'Another', 'familyName' => 'User', 'formatted' => 'Another User'], self::json($replaced)['name'] ?? null);
        self::assertArrayNotHasKey('nickName', self::json($replaced));
        self::assertTrue(password_verify('1mz050nq', (string) $this->user($id)->getPassword()));
    }

    #[Test]
    #[TestDox('1.4.6 A PUT that takes the userName of another user in another case answers 409 uniqueness')]
    public function rejectsUserNameCollision(): void
    {
        [$id] = $this->oktaUsers('test.user@okta.local', 'babs@okta.local');
        $user = self::json($this->okta('GET', '/scim/v2/Users/'.$id));
        $user['userName'] = 'BABS@okta.local';

        self::assertScimError($this->okta('PUT', '/scim/v2/Users/'.$id, $user), 409, 'uniqueness', 'The value of attribute "userName" is already in use.');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function activations(): iterable
    {
        yield '1.4.7 deactivate' => [false];
        yield '1.4.8 reactivate' => [true];
    }

    #[Test]
    #[DataProvider('activations')]
    #[TestDox('$_dataName: path-less PATCH replace of active answers 204, then GET returns the same id')]
    public function patchesActive(bool $active): void
    {
        [$id] = $this->oktaUsers('test.user@okta.local');
        $this->user($id)->setEnabled(!$active);
        self::getContainer()->get('doctrine')->getManager()->flush();

        self::assertNoContent($this->okta('PATCH', '/scim/v2/Users/'.$id, self::patchOp(['op' => 'replace', 'value' => ['active' => $active]])));

        $user = self::json($this->okta('GET', '/scim/v2/Users/'.$id));
        self::assertSame([$id, $active], [$user['id'] ?? null, $user['active'] ?? null]);
    }

    #[Test]
    #[DataProvider('activations')]
    #[TestDox('$_dataName, AIW integration: PUT of the GET output with active changed answers 200')]
    public function replacesActive(bool $active): void
    {
        [$id] = $this->oktaUsers('test.user@okta.local');
        $user = self::json($this->okta('GET', '/scim/v2/Users/'.$id));
        $user['active'] = $active;

        $replaced = self::json($this->okta('PUT', '/scim/v2/Users/'.$id, $user));

        self::assertSame([$id, $active], [$replaced['id'] ?? null, $replaced['active'] ?? null]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function passwordSyncs(): iterable
    {
        yield 'path-less' => [['op' => 'replace', 'value' => ['password' => 'n3wPassw0rd']]];
        yield 'with path' => [['op' => 'replace', 'path' => 'password', 'value' => 'n3wPassw0rd']];
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('passwordSyncs')]
    #[TestDox('1.4.9 Password sync $_dataName answers 204 and stores the hash; GET never returns the password')]
    public function syncsPassword(array $operation): void
    {
        [$id] = $this->oktaUsers('test.user@okta.local');

        self::assertNoContent($this->okta('PATCH', '/scim/v2/Users/'.$id, self::patchOp($operation)));

        self::assertTrue(password_verify('n3wPassw0rd', (string) $this->user($id)->getPassword()));
        self::assertArrayNotHasKey('password', self::json($this->okta('GET', '/scim/v2/Users/'.$id)));
    }

    #[Test]
    #[TestDox('1.4.10 Group Push: POST with members [] answers 201 with the group')]
    public function pushesGroup(): void
    {
        $created = $this->okta('POST', '/scim/v2/Groups', ['schemas' => [Scim::GROUP], 'displayName' => 'Test SCIMv2', 'members' => []]);

        $id = self::idOf($created);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame([
            'schemas' => [Scim::GROUP],
            'id' => $id,
            'meta' => ['resourceType' => 'Group', 'created' => '2026-11-06T10:00:00Z', 'lastModified' => '2026-11-06T10:00:00Z', 'version' => $created->headers->get('ETag'), 'location' => Scim::BASE_URL.'/Groups/'.$id],
            'displayName' => 'Test SCIMv2',
        ], self::json($created));
    }

    #[Test]
    #[TestDox('1.4.11 and 1.4.12 Import groups, lookup by displayName, SPEC test GET /Groups and GET of one group return members')]
    public function importsGroups(): void
    {
        [$member] = $this->oktaUsers('test.user@okta.local');
        $group = $this->oktaGroup('Test SCIMv2', [$member]);
        $other = $this->oktaGroup('Tour Guides');

        $import = self::resources($this->okta('GET', '/scim/v2/Groups?startIndex=1&count=100'));
        $lookup = self::resources($this->okta('GET', '/scim/v2/Groups?filter=displayName%20eq%20%22Test%20SCIMv2%22&startIndex=1&count=100'));

        self::assertSame([$group, $other], array_column($import, 'id'));
        self::assertSame([[$member]], array_map(static fn (array $found): array => array_column((array) ($found['members'] ?? []), 'value'), $lookup));
        self::assertSame([$group, $other], self::ids($this->send('GET', '/scim/v2/Groups', headers: self::SPEC_TEST_GET)));
        self::assertSame([], self::ids($this->okta('GET', '/scim/v2/Groups?filter=displayName%20eq%20%22Unknown%22&startIndex=1&count=100')));
        self::assertSame([$member], array_column((array) (self::json($this->okta('GET', '/scim/v2/Groups/'.$group))['members'] ?? []), 'value'));
    }

    #[Test]
    #[TestDox('1.4.13 Rename: path-less replace with the readOnly id answers 204; the same rename again writes nothing')]
    public function renamesGroup(): void
    {
        [$member] = $this->oktaUsers('test.user@okta.local');
        $group = $this->oktaGroup('Test SCIMv1', [$member]);
        $rename = self::patchOp(['op' => 'replace', 'value' => ['id' => $group, 'displayName' => 'Test SCIMv2']]);
        $recorder = self::getContainer()->get(ProvisioningRecorder::class);

        $renamed = $this->okta('PATCH', '/scim/v2/Groups/'.$group, $rename);
        $events = \count($recorder->events);
        $again = $this->okta('PATCH', '/scim/v2/Groups/'.$group, $rename);

        self::assertNoContent($renamed);
        self::assertNoContent($again);
        self::assertSame($renamed->headers->get('ETag'), $again->headers->get('ETag'));
        self::assertCount($events, $recorder->events);
        $stored = self::json($this->okta('GET', '/scim/v2/Groups/'.$group));
        self::assertSame(['Test SCIMv2', [$member]], [$stored['displayName'] ?? null, array_column((array) ($stored['members'] ?? []), 'value')]);
    }

    #[Test]
    #[TestDox('1.4.13 Rename with another id in the value answers 400 mutability')]
    public function rejectsOtherIdInRename(): void
    {
        $group = $this->oktaGroup('Test SCIMv1');

        $response = $this->okta('PATCH', '/scim/v2/Groups/'.$group, self::patchOp(['op' => 'replace', 'value' => ['id' => 'e9e30dba-f08f-4109-8486-d5c6a331660a', 'displayName' => 'Test SCIMv2']]));

        self::assertScimError($response, 400, 'mutability', 'Operations[0]: Attribute "urn:ietf:params:scim:schemas:core:2.0:Group:id" is readOnly.');
    }

    #[Test]
    #[TestDox('1.4.13 and 1.4.14 AIW: PUT with another id and members with display answers 200; the URL id wins')]
    public function replacesGroup(): void
    {
        [$babs, $mandy] = $this->oktaUsers('babs@okta.local', 'mandy@okta.local');
        $group = $this->oktaGroup('Tour Operators');

        $replaced = $this->okta('PUT', '/scim/v2/Groups/'.$group, [
            'schemas' => [Scim::GROUP],
            'id' => 'e9e30dba-f08f-4109-8486-d5c6a331660a',
            'displayName' => 'Tour Guides',
            'members' => [['value' => $babs, 'display' => 'Babs Jensen'], ['value' => $mandy, 'display' => 'Mandy Pepperidge']],
        ]);

        $body = self::json($replaced);
        self::assertSame(200, $replaced->getStatusCode());
        self::assertSame([$group, 'Tour Guides'], [$body['id'] ?? null, $body['displayName'] ?? null]);
        self::assertEquals([$babs => 'babs@okta.local', $mandy => 'mandy@okta.local'], array_column((array) ($body['members'] ?? []), 'display', 'value'));
    }

    #[Test]
    #[TestDox('1.4.14 Membership: remove by value filter and add with display in one request answer 204, also when resent')]
    public function changesMembership(): void
    {
        [$user, $other, $third] = $this->oktaUsers('test.user@okta.local', 'babs@okta.local', 'jsmith@okta.local');
        $group = $this->oktaGroup('Test SCIMv2', [$other, $third]);
        $change = self::patchOp(
            ['op' => 'remove', 'path' => 'members[value eq "'.$other.'"]'],
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => $user, 'display' => 'test.user@okta.local']]],
        );

        self::assertNoContent($this->okta('PATCH', '/scim/v2/Groups/'.$group, $change));
        self::assertNoContent($this->okta('PATCH', '/scim/v2/Groups/'.$group, $change));

        self::assertEqualsCanonicalizing([$third, $user], array_column((array) (self::json($this->okta('GET', '/scim/v2/Groups/'.$group))['members'] ?? []), 'value'));
    }

    #[Test]
    #[TestDox('1.4.14 Membership: a request whose second operation fails changes nothing')]
    public function changesMembershipAtomically(): void
    {
        [$other] = $this->oktaUsers('babs@okta.local');
        $group = $this->oktaGroup('Test SCIMv2', [$other]);

        $response = $this->okta('PATCH', '/scim/v2/Groups/'.$group, self::patchOp(
            ['op' => 'remove', 'path' => 'members[value eq "'.$other.'"]'],
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => 42]]],
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([$other], array_column((array) (self::json($this->okta('GET', '/scim/v2/Groups/'.$group))['members'] ?? []), 'value'));
    }

    #[Test]
    #[TestDox('1.4.14 Full membership push: PATCH replace of members with the full list, then with an empty list')]
    public function pushesMembership(): void
    {
        [$user, $other, $third] = $this->oktaUsers('test.user@okta.local', 'babs@okta.local', 'jsmith@okta.local');
        $group = $this->oktaGroup('Test SCIMv2', [$third]);

        self::assertNoContent($this->okta('PATCH', '/scim/v2/Groups/'.$group, self::patchOp(['op' => 'replace', 'path' => 'members', 'value' => [['value' => $user, 'display' => 'test.user@okta.local'], ['value' => $other, 'display' => 'test.user@okta.local']]])));
        self::assertEqualsCanonicalizing([$user, $other], array_column((array) (self::json($this->okta('GET', '/scim/v2/Groups/'.$group))['members'] ?? []), 'value'));

        self::assertNoContent($this->okta('PATCH', '/scim/v2/Groups/'.$group, self::patchOp(['op' => 'replace', 'path' => 'members', 'value' => []])));
        self::assertArrayNotHasKey('members', self::json($this->okta('GET', '/scim/v2/Groups/'.$group)));
    }

    #[Test]
    #[TestDox('1.4.15 Group delete answers 204 and leaves the member users active')]
    public function deletesGroup(): void
    {
        [$user] = $this->oktaUsers('test.user@okta.local');
        $group = $this->oktaGroup('Test SCIMv2', [$user]);

        self::assertNoContent($this->okta('DELETE', '/scim/v2/Groups/'.$group));

        self::assertScimError($this->okta('GET', '/scim/v2/Groups/'.$group), 404, null, 'Resource '.$group.' not found.');
        self::assertTrue(self::json($this->okta('GET', '/scim/v2/Users/'.$user))['active'] ?? null);
    }

    #[Test]
    #[TestDox('1.4.16 Import User Schema: /Schemas has the User schemas and /ResourceTypes names the extension URN')]
    public function servesSchemaImport(): void
    {
        $schemas = self::resources($this->okta('GET', '/scim/v2/Schemas'));
        $user = array_find(self::resources($this->okta('GET', '/scim/v2/ResourceTypes')), static fn (array $type): bool => 'User' === ($type['name'] ?? null)) ?? [];

        self::assertContains(Scim::USER, array_column($schemas, 'id'));
        self::assertContains(Scim::ENTERPRISE, array_column($schemas, 'id'));
        self::assertSame([['schema' => Scim::ENTERPRISE, 'required' => false]], $user['schemaExtensions'] ?? null);
    }

    /**
     * @param array<array-key, mixed>|null $body
     */
    private function okta(string $method, string $uri, ?array $body = null): Response
    {
        return $this->send($method, $uri, $body, Scim::OKTA);
    }

    /**
     * @return list<string> the ids
     */
    private function oktaUsers(string ...$userNames): array
    {
        return array_values(array_map(fn (string $userName): string => self::idOf($this->okta('POST', '/scim/v2/Users', [
            'schemas' => [Scim::USER],
            'userName' => $userName,
            'name' => ['givenName' => 'Test', 'familyName' => 'User'],
            'emails' => [['primary' => true, 'value' => $userName, 'type' => 'work']],
            'active' => true,
        ])), $userNames));
    }

    /**
     * @param list<string> $members
     */
    private function oktaGroup(string $displayName, array $members = []): string
    {
        return self::idOf($this->okta('POST', '/scim/v2/Groups', ['schemas' => [Scim::GROUP], 'displayName' => $displayName, 'members' => array_map(static fn (string $member): array => ['value' => $member], $members)]));
    }

    private function user(string $id): User
    {
        $user = self::getContainer()->get('doctrine')->getManager()->find(User::class, Uuid::fromString($id));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
