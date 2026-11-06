<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Tests\Conformance\Fixtures\IdpClient;

/**
 * Request sequences of Okta. The production requests carry the documented headers only; Okta documents no
 * Content-Type for them. The SPEC test requests carry its headers.
 */
final class OktaTest extends KernelTestCase
{
    use IdpClient;
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const array HEADERS = ['User-Agent' => 'Okta SCIM Client 1.0.0', 'Authorization' => 'secret-token'];
    private const array BODY_HEADERS = [];
    private const array SPEC_TEST_POST = ['User-Agent' => 'OKTA SCIM Integration', 'Content-Type' => 'application/json', 'Accept' => 'application/scim+json; charset=utf-8'];
    private const array SPEC_TEST_GET = ['User-Agent' => 'OKTA SCIM Integration', 'Content-Type' => 'application/scim+json; charset=utf-8', 'Accept' => 'application/scim+json', 'Accept-Charset' => 'utf-8'];
    private const string USER_ID = '23a35c27-23d3-4c03-b4c5-6443c09e7173';
    private const string OTHER_ID = '89bb1940-b905-4575-9e7f-6f887cfb368e';
    private const string GROUP_ID = 'abf4dd94-a4c0-4f67-89c9-76b03340cb9b';

    #[Test]
    #[TestDox('Provisioning enabled: GET /Users?startIndex=1&count=2 and GET /Groups?startIndex=1&count=100 answer ListResponses with integers')]
    public function answersFirstRequests(): void
    {
        $this->seed([self::oktaUser(self::USER_ID, 'test.user@okta.local'), self::oktaUser(self::OTHER_ID, 'babs@okta.local'), self::oktaUser('u3', 'jsmith@okta.local')], [self::storedGroup(self::GROUP_ID, 'Test SCIMv2')]);

        $users = self::json($this->request('GET', '/Users?startIndex=1&count=2'));
        $groups = self::json($this->request('GET', '/Groups?startIndex=1&count=100'));

        self::assertSame([self::LIST_RESPONSE, 3, 2, 1], [$users['schemas'][0] ?? null, $users['totalResults'] ?? null, $users['itemsPerPage'] ?? null, $users['startIndex'] ?? null]);
        self::assertSame([self::LIST_RESPONSE, 1, 1, 1], [$groups['schemas'][0] ?? null, $groups['totalResults'] ?? null, $groups['itemsPerPage'] ?? null, $groups['startIndex'] ?? null]);
    }

    #[Test]
    #[TestDox('User assignment: the userName filter that matches nothing answers the preferred empty ListResponse')]
    public function answersEmptyUserNameFilter(): void
    {
        $this->seed([self::oktaUser(self::OTHER_ID, 'babs@okta.local')]);

        self::assertEmptyListResponse($this->request('GET', '/Users?filter=userName%20eq%20%22test.user%40okta.local%22&startIndex=1&count=100'));
    }

    #[Test]
    #[TestDox('The userName filter matches in any case (SPEC test Username Case Sensitivity Check)')]
    public function matchesUserNameInAnyCase(): void
    {
        $this->seed([self::oktaUser(self::USER_ID, 'test.user@okta.local')]);

        self::assertSame([self::USER_ID], self::ids($this->request('GET', '/Users?filter=userName%20eq%20%22TEST.USER%40OKTA.LOCAL%22&startIndex=1&count=100')));
        self::assertSame([self::USER_ID], self::ids($this->request('GET', '/Users?filter=userName eq "TEST.USER@OKTA.LOCAL"', headers: self::SPEC_TEST_GET)));
    }

    #[Test]
    #[TestDox('Import users: pages of 100 with startIndex+100, a stable order, inactive users and full representations')]
    public function importsUsers(): void
    {
        $this->seed(array_map(static fn (int $i): array => self::oktaUser('u'.$i, 'user'.$i.'@okta.local', ['active' => 150 !== $i]), range(1, 150)));

        $first = self::json($this->request('GET', '/Users?startIndex=1&count=100'));
        $second = self::json($this->request('GET', '/Users?startIndex=101&count=100'));
        $again = self::json($this->request('GET', '/Users?startIndex=1&count=100'));

        self::assertSame([150, 100, 1], [$first['totalResults'] ?? null, $first['itemsPerPage'] ?? null, $first['startIndex'] ?? null]);
        self::assertSame([150, 50, 101], [$second['totalResults'] ?? null, $second['itemsPerPage'] ?? null, $second['startIndex'] ?? null]);
        self::assertSame(array_map(static fn (int $i): string => 'u'.$i, range(1, 150)), [...array_column((array) ($first['Resources'] ?? []), 'id'), ...array_column((array) ($second['Resources'] ?? []), 'id')]);
        self::assertSame($first['Resources'] ?? null, $again['Resources'] ?? null);
        self::assertFalse($second['Resources'][49]['active'] ?? null);
    }

    #[Test]
    #[TestDox('SPEC test GET /Users?count=1&startIndex=1 returns one full user')]
    public function answersSpecTestPage(): void
    {
        $this->seed([self::oktaUser(self::USER_ID, 'test.user@okta.local'), self::oktaUser(self::OTHER_ID, 'babs@okta.local')]);

        $page = self::json($this->request('GET', '/Users?count=1&startIndex=1', headers: self::SPEC_TEST_GET));
        $user = $page['Resources'][0] ?? [];

        self::assertSame([2, 1, 1], [$page['totalResults'] ?? null, $page['itemsPerPage'] ?? null, $page['startIndex'] ?? null]);
        self::assertSame([self::USER_ID, 'User', 'Test', 'test.user@okta.local', true, 'test.user@okta.local'], [$user['id'] ?? null, $user['name']['familyName'] ?? null, $user['name']['givenName'] ?? null, $user['userName'] ?? null, $user['active'] ?? null, $user['emails'][0]['value'] ?? null]);
    }

    #[Test]
    #[TestDox('Create user: POST with groups [] and password answers 201 with the user and Location, without password')]
    public function createsUser(): void
    {
        $this->seed();

        $created = $this->request('POST', '/Users', [
            'schemas' => [self::USER],
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
        self::assertSame(201, $created->getStatusCode());
        self::assertSame(self::BASE_URL.'/Users/'.$id, $created->headers->get('Location'));
        self::assertSame([
            'schemas' => [self::USER],
            'id' => $id,
            'meta' => ['resourceType' => 'User', 'created' => '2026-11-05T10:00:00Z', 'lastModified' => '2026-11-05T10:00:00Z', 'version' => 'W/"1"', 'location' => self::BASE_URL.'/Users/'.$id],
            'externalId' => '00ujl29u0le5T6Aj10h7',
            'userName' => 'test.user@okta.local',
            'name' => ['givenName' => 'Test', 'familyName' => 'User'],
            'displayName' => 'Test User',
            'locale' => 'en-US',
            'active' => true,
            'emails' => [['primary' => true, 'value' => 'test.user@okta.local', 'type' => 'work']],
        ], self::json($created));
        self::assertSame('1mz050nq', ($this->users->get($id) ?? [])['password'] ?? null);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id);
    }

    #[Test]
    #[TestDox('SPEC test create with application/json answers 201; the same POST again answers 409 uniqueness')]
    public function createsSpecTestUser(): void
    {
        $this->seed();
        $body = ['schemas' => [self::USER], 'userName' => 'Ms.Beryl@example.com', 'name' => ['givenName' => 'Beryl', 'familyName' => 'Kassulke'], 'emails' => [['primary' => true, 'value' => 'beryl@example.com', 'type' => 'work']], 'displayName' => 'Beryl Kassulke', 'active' => true];

        $created = $this->request('POST', '/Users', $body, self::SPEC_TEST_POST);
        $user = self::json($created);
        $duplicate = $this->request('POST', '/Users', $body, self::SPEC_TEST_POST);

        self::assertSame(201, $created->getStatusCode());
        self::assertSame('application/scim+json', $created->headers->get('Content-Type'));
        self::assertSame([true, 'Kassulke', 'Beryl', [self::USER], 'Ms.Beryl@example.com'], [$user['active'] ?? null, $user['name']['familyName'] ?? null, $user['name']['givenName'] ?? null, $user['schemas'] ?? null, $user['userName'] ?? null]);
        self::assertScimError($duplicate, 409, 'uniqueness', 'The value of attribute "userName" is already in use.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIds(): iterable
    {
        yield '010101001010101011001010101011' => ['010101001010101011001010101011'];
        yield '00919288221112222' => ['00919288221112222'];
    }

    #[Test]
    #[DataProvider('unknownIds')]
    #[TestDox('SPEC test GET of the unknown id $_dataName answers 404 with an Error body')]
    public function answersUnknownId(string $id): void
    {
        $this->seed([self::oktaUser(self::USER_ID, 'test.user@okta.local')]);

        self::assertScimError($this->request('GET', '/Users/'.$id, headers: self::SPEC_TEST_GET), 404, null, 'Resource '.$id.' not found.');
    }

    #[Test]
    #[TestDox('Profile update: PUT of the GET output with id, meta, groups and "" answers 200 and keeps the password')]
    public function replacesUserFromGetOutput(): void
    {
        $this->seed([self::oktaUser(self::USER_ID, 'test.user@okta.local', ['nickName' => 'Testy', 'password' => '1mz050nq'])]);
        $user = self::json($this->request('GET', '/Users/'.self::USER_ID));
        unset($user['nickName']);
        $user['name'] = ['givenName' => 'Another', 'middleName' => 'Excited', 'familyName' => 'User'];
        $user['groups'] = [];

        $replaced = $this->request('PUT', '/Users/'.self::USER_ID, $user);

        $stored = $this->users->get(self::USER_ID) ?? [];
        self::assertSame(200, $replaced->getStatusCode());
        self::assertSame('W/"2"', $replaced->headers->get('ETag'));
        self::assertSame(['givenName' => 'Another', 'middleName' => 'Excited', 'familyName' => 'User'], self::json($replaced)['name'] ?? null);
        self::assertSame([self::USER_ID, '1mz050nq'], [$stored['id'] ?? null, $stored['password'] ?? null]);
        self::assertArrayNotHasKey('nickName', $stored);
    }

    #[Test]
    #[TestDox('The GET output keeps empty strings, and a PUT that sends them back answers 200')]
    public function keepsEmptyStrings(): void
    {
        $this->seed([self::oktaUser(self::USER_ID, 'test.user@okta.local', ['name' => ['givenName' => 'Test', 'middleName' => '', 'familyName' => 'User'], 'title' => ''])]);

        $user = self::json($this->request('GET', '/Users/'.self::USER_ID));
        $replaced = $this->request('PUT', '/Users/'.self::USER_ID, $user);

        self::assertSame([['givenName' => 'Test', 'middleName' => '', 'familyName' => 'User'], ''], [$user['name'] ?? null, $user['title'] ?? null]);
        self::assertSame(200, $replaced->getStatusCode());
        self::assertSame([['givenName' => 'Test', 'middleName' => '', 'familyName' => 'User'], ''], [self::json($replaced)['name'] ?? null, self::json($replaced)['title'] ?? null]);
    }

    #[Test]
    #[TestDox('A PUT that takes the userName of another user answers 409 uniqueness')]
    public function rejectsUserNameCollision(): void
    {
        $this->seed([self::oktaUser(self::USER_ID, 'test.user@okta.local'), self::oktaUser(self::OTHER_ID, 'babs@okta.local')]);
        $user = self::json($this->request('GET', '/Users/'.self::USER_ID));
        $user['userName'] = 'BABS@okta.local';

        self::assertScimError($this->request('PUT', '/Users/'.self::USER_ID, $user), 409, 'uniqueness', 'The value of attribute "userName" is already in use.');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function activations(): iterable
    {
        yield '1.4.7 deactivate' => ['/Users/'.self::USER_ID, false];
        yield '1.4.8 reactivate' => ['/Users/'.self::USER_ID, true];
    }

    #[Test]
    #[DataProvider('activations')]
    #[TestDox('$_dataName: path-less PATCH replace of active answers 204, then GET returns the same id')]
    public function patchesActive(string $uri, bool $active): void
    {
        $this->seed([self::oktaUser(self::USER_ID, 'test.user@okta.local', ['active' => !$active])]);

        self::assertNoContent($this->request('PATCH', $uri, self::patchOp(['op' => 'replace', 'value' => ['active' => $active]])));

        $user = self::json($this->request('GET', $uri));
        self::assertSame([self::USER_ID, $active], [$user['id'] ?? null, $user['active'] ?? null]);
    }

    #[Test]
    #[DataProvider('activations')]
    #[TestDox('$_dataName, AIW integration: PUT of the GET output with active changed answers 200')]
    public function replacesActive(string $uri, bool $active): void
    {
        $this->seed([self::oktaUser(self::USER_ID, 'test.user@okta.local', ['active' => !$active])]);
        $user = self::json($this->request('GET', $uri));
        $user['active'] = $active;

        $replaced = $this->request('PUT', $uri, $user);

        self::assertSame(200, $replaced->getStatusCode());
        self::assertSame([self::USER_ID, $active], [self::json($replaced)['id'] ?? null, self::json($replaced)['active'] ?? null]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function passwordSyncs(): iterable
    {
        yield 'path-less, by analogy with 1.4.7' => [['op' => 'replace', 'value' => ['password' => 'n3wPassw0rd']]];
        yield 'with path' => [['op' => 'replace', 'path' => 'password', 'value' => 'n3wPassw0rd']];
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('passwordSyncs')]
    #[TestDox('Password sync $_dataName answers 204; GET never returns the password')]
    public function syncsPassword(array $operation): void
    {
        $this->seed([self::oktaUser(self::USER_ID, 'test.user@okta.local', ['password' => '1mz050nq'])]);

        self::assertNoContent($this->request('PATCH', '/Users/'.self::USER_ID, self::patchOp($operation)));

        self::assertSame('n3wPassw0rd', ($this->users->get(self::USER_ID) ?? [])['password'] ?? null);
        self::assertArrayNotHasKey('password', self::json($this->request('GET', '/Users/'.self::USER_ID)));
    }

    #[Test]
    #[TestDox('Group Push: POST with members [] answers 201 with the group')]
    public function pushesGroup(): void
    {
        $this->seed();

        $created = $this->request('POST', '/Groups', ['schemas' => [self::GROUP], 'displayName' => 'Test SCIMv2', 'members' => []]);

        $id = self::idOf($created);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame([
            'schemas' => [self::GROUP],
            'id' => $id,
            'meta' => ['resourceType' => 'Group', 'created' => '2026-11-05T10:00:00Z', 'lastModified' => '2026-11-05T10:00:00Z', 'version' => 'W/"1"', 'location' => self::BASE_URL.'/Groups/'.$id],
            'displayName' => 'Test SCIMv2',
        ], self::json($created));
    }

    #[Test]
    #[TestDox('Import groups, lookup by displayName and SPEC test GET /Groups without parameters return members')]
    public function importsGroups(): void
    {
        $this->seed(groups: [self::storedGroup(self::GROUP_ID, 'Test SCIMv2', ['members' => [['value' => self::USER_ID]]]), self::storedGroup('g2', 'Tour Guides')]);

        $import = self::resources($this->request('GET', '/Groups?startIndex=1&count=100'));
        $lookup = self::resources($this->request('GET', '/Groups?filter=displayName%20eq%20%22Test%20SCIMv2%22&startIndex=1&count=100'));

        self::assertSame([self::GROUP_ID, 'g2'], array_column($import, 'id'));
        self::assertSame([[['value' => self::USER_ID]]], array_column($lookup, 'members'));
        self::assertSame([self::GROUP_ID, 'g2'], self::ids($this->request('GET', '/Groups', headers: self::SPEC_TEST_GET)));
        self::assertEmptyListResponse($this->request('GET', '/Groups?filter=displayName%20eq%20%22Unknown%22&startIndex=1&count=100'));
    }

    #[Test]
    #[TestDox('GET /Groups/{id} without parameters returns the members')]
    public function retrievesGroupWithMembers(): void
    {
        $this->seed(groups: [self::storedGroup(self::GROUP_ID, 'Test SCIMv2', ['members' => [['value' => 'b1c794f24f4c49f4b5d503a4cb2686ea']]])]);

        self::assertSame([['value' => 'b1c794f24f4c49f4b5d503a4cb2686ea']], self::json($this->request('GET', '/Groups/'.self::GROUP_ID))['members'] ?? null);
    }

    #[Test]
    #[TestDox('Rename: path-less replace with the readOnly id answers 204; the same rename again writes nothing')]
    public function renamesGroup(): void
    {
        $this->seed(groups: [self::storedGroup(self::GROUP_ID, 'Test SCIMv1', ['members' => [['value' => self::USER_ID]]])]);
        $rename = self::patchOp(['op' => 'replace', 'value' => ['id' => self::GROUP_ID, 'displayName' => 'Test SCIMv2']]);

        $renamed = $this->request('PATCH', '/Groups/'.self::GROUP_ID, $rename);
        $events = \count($this->events);
        $again = $this->request('PATCH', '/Groups/'.self::GROUP_ID, $rename);

        self::assertNoContent($renamed);
        self::assertNoContent($again);
        self::assertSame(['W/"2"', 'W/"2"'], [$renamed->headers->get('ETag'), $again->headers->get('ETag')]);
        self::assertCount($events, $this->events);
        self::assertSame(['Test SCIMv2', [['value' => self::USER_ID]]], [($this->groups->get(self::GROUP_ID) ?? [])['displayName'] ?? null, ($this->groups->get(self::GROUP_ID) ?? [])['members'] ?? null]);
    }

    #[Test]
    #[TestDox('Rename with another id in the value answers 400 mutability: id is readOnly (RFC 7643 section 7)')]
    public function rejectsOtherIdInRename(): void
    {
        $this->seed(groups: [self::storedGroup(self::GROUP_ID, 'Test SCIMv1')]);

        $response = $this->request('PATCH', '/Groups/'.self::GROUP_ID, self::patchOp(['op' => 'replace', 'value' => ['id' => 'e9e30dba-f08f-4109-8486-d5c6a331660a', 'displayName' => 'Test SCIMv2']]));

        self::assertScimError($response, 400, 'mutability', 'Operations[0]: Attribute "urn:ietf:params:scim:schemas:core:2.0:Group:id" is readOnly.');
    }

    #[Test]
    #[TestDox('AIW rename: PUT with another id in the body answers 200; the URL id wins and members.display is dropped')]
    public function replacesGroup(): void
    {
        $this->seed(groups: [self::storedGroup('U0FP0NMEE', 'Tour Operators')]);

        $replaced = $this->request('PUT', '/Groups/U0FP0NMEE', [
            'schemas' => [self::GROUP],
            'id' => 'e9e30dba-f08f-4109-8486-d5c6a331660a',
            'displayName' => 'Tour Guides',
            'members' => [['value' => 'some-member-1', 'display' => 'Babs Jensen'], ['value' => 'some-member-2', 'display' => 'Mandy Pepperidge']],
        ]);

        $group = self::json($replaced);
        self::assertSame(200, $replaced->getStatusCode());
        self::assertSame(['U0FP0NMEE', 'Tour Guides', [['value' => 'some-member-1'], ['value' => 'some-member-2']]], [$group['id'] ?? null, $group['displayName'] ?? null, $group['members'] ?? null]);
        self::assertNull($this->groups->get('e9e30dba-f08f-4109-8486-d5c6a331660a'));
    }

    #[Test]
    #[TestDox('Membership: remove by value filter and add with display in one request answer 204')]
    public function changesMembership(): void
    {
        $this->seed(groups: [self::storedGroup(self::GROUP_ID, 'Test SCIMv2', ['members' => [['value' => self::OTHER_ID], ['value' => 'u3']]])]);
        $change = self::patchOp(
            ['op' => 'remove', 'path' => 'members[value eq "'.self::OTHER_ID.'"]'],
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => self::USER_ID, 'display' => 'test.user@okta.local']]],
        );

        self::assertNoContent($this->request('PATCH', '/Groups/'.self::GROUP_ID, $change));
        self::assertNoContent($this->request('PATCH', '/Groups/'.self::GROUP_ID, $change));

        self::assertSame([['value' => 'u3'], ['value' => self::USER_ID]], ($this->groups->get(self::GROUP_ID) ?? [])['members'] ?? null);
    }

    #[Test]
    #[TestDox('Membership: a request whose second operation fails changes nothing (atomic)')]
    public function changesMembershipAtomically(): void
    {
        $stored = self::storedGroup(self::GROUP_ID, 'Test SCIMv2', ['members' => [['value' => self::OTHER_ID]]]);
        $this->seed(groups: [$stored]);

        $response = $this->request('PATCH', '/Groups/'.self::GROUP_ID, self::patchOp(
            ['op' => 'remove', 'path' => 'members[value eq "'.self::OTHER_ID.'"]'],
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => 42]]],
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame($stored, $this->groups->get(self::GROUP_ID));
    }

    /**
     * @return iterable<string, array{list<array<string, string>>, list<array<string, string>>|null}>
     */
    public static function membershipPushes(): iterable
    {
        yield 'the full list' => [
            [['value' => self::USER_ID, 'display' => 'test.user@okta.local'], ['value' => self::OTHER_ID, 'display' => 'test.user@okta.local']],
            [['value' => self::USER_ID], ['value' => self::OTHER_ID]],
        ];
        yield 'an empty list' => [[], null];
    }

    /**
     * @param list<array<string, string>>      $members
     * @param list<array<string, string>>|null $expected
     */
    #[Test]
    #[DataProvider('membershipPushes')]
    #[TestDox('Full membership push: PATCH replace of members with $_dataName replaces all members')]
    public function pushesMembership(array $members, ?array $expected): void
    {
        $this->seed(groups: [self::storedGroup(self::GROUP_ID, 'Test SCIMv2', ['members' => [['value' => 'u3']]])]);

        self::assertNoContent($this->request('PATCH', '/Groups/'.self::GROUP_ID, self::patchOp(['op' => 'replace', 'path' => 'members', 'value' => $members])));

        self::assertSame($expected, ($this->groups->get(self::GROUP_ID) ?? [])['members'] ?? null);
    }

    #[Test]
    #[TestDox('AIW membership: PUT with the full member list answers 200 with the group')]
    public function replacesMembership(): void
    {
        $this->seed(groups: [self::storedGroup(self::GROUP_ID, 'Test SCIMv2', ['members' => [['value' => 'u3']]])]);

        $replaced = $this->request('PUT', '/Groups/'.self::GROUP_ID, ['schemas' => [self::GROUP], 'displayName' => 'Test SCIMv2', 'members' => [['value' => self::USER_ID, 'display' => 'test.user@okta.local']]]);

        self::assertSame(200, $replaced->getStatusCode());
        self::assertSame([self::GROUP_ID, 'Test SCIMv2', [['value' => self::USER_ID]]], [self::json($replaced)['id'] ?? null, self::json($replaced)['displayName'] ?? null, self::json($replaced)['members'] ?? null]);
    }

    #[Test]
    #[TestDox('Group delete answers 204 and leaves the member users active')]
    public function deletesGroup(): void
    {
        $user = self::oktaUser(self::USER_ID, 'test.user@okta.local');
        $this->seed([$user], [self::storedGroup(self::GROUP_ID, 'Test SCIMv2', ['members' => [['value' => self::USER_ID]]])]);

        self::assertNoContent($this->request('DELETE', '/Groups/'.self::GROUP_ID));

        self::assertNull($this->groups->get(self::GROUP_ID));
        self::assertSame($user, $this->users->get(self::USER_ID));
    }

    #[Test]
    #[TestDox('Import User Schema: /Schemas has the core User schema and /ResourceTypes names the extension URN')]
    public function servesSchemaImport(): void
    {
        $this->seed();

        $schemas = self::resources($this->request('GET', '/Schemas'));
        $resourceTypes = self::resources($this->request('GET', '/ResourceTypes'));

        self::assertContains(self::USER, array_column($schemas, 'id'));
        self::assertContains(self::ENTERPRISE, array_column($schemas, 'id'));
        self::assertSame([[['schema' => self::ENTERPRISE, 'required' => false]], []], array_column($resourceTypes, 'schemaExtensions'));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function oktaUser(string $id, string $userName, array $extra = []): array
    {
        return self::storedUser($id, $userName, [
            'name' => ['givenName' => 'Test', 'familyName' => 'User'],
            'active' => true,
            'emails' => [['primary' => true, 'value' => $userName, 'type' => 'work', 'display' => $userName]],
            ...$extra,
        ]);
    }
}
