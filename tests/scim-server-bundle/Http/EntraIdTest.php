<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Microsoft Entra ID, default style and aadOptscim062020 style, with the request bodies of
 * tests/scim-server/Conformance/EntraIdTest.php. Requests for attributes that the fixture User does not map (roles,
 * emails of type other) are left out: they answer 400 invalidPath.
 */
final class EntraIdTest extends WebTestCase
{
    use ScimClient;
    private const string GUID = '5f8c2c9e-3d4b-4a8e-9b1a-1d2e3f4a5b6c';
    private const string USER_NAME = 'Test_User_00aa00aa-bb11-cc22-dd33-44ee44ee44ee';
    private const string EMAIL = 'Test_User_11bb11bb-cc22-dd33-ee44-55ff55ff55ff@testuser.com';
    private const string EXTERNAL_ID = '0a21f0f2-8d2a-4f8e-bf98-7363c4aed4ef';

    /**
     * @return iterable<string, array{string}>
     */
    public static function connectionQueries(): iterable
    {
        yield 'as documented' => ['/scim/v2/Users?filter=userName eq "'.self::GUID.'"'];
        yield 'spaces as %20, quotes as %22' => ['/scim/v2/Users?filter=userName%20eq%20%22'.self::GUID.'%22'];
        yield 'spaces as +' => ['/scim/v2/Users?filter=userName+eq+%22'.self::GUID.'%22'];
        yield 'a Tenant URL with a trailing slash' => ['/scim/v2//Users?filter=userName eq "'.self::GUID.'"'];
        yield 'a Tenant URL with ?aadOptscim062020' => ['/scim/v2/Users?aadOptscim062020&filter=userName eq "'.self::GUID.'"'];
        yield 'the group request' => ['/scim/v2/Groups?excludedAttributes=members&filter=displayName eq "'.self::GUID.'"'];
    }

    #[Test]
    #[DataProvider('connectionQueries')]
    #[TestDox('4.1 Test Connection answers 200 with an empty ListResponse for $_dataName')]
    public function answersTestConnection(string $uri): void
    {
        $this->entraUser();

        $response = $this->send('GET', $uri);

        self::assertSame('application/scim+json', $response->headers->get('Content-Type'));
        self::assertSame(['schemas' => [Scim::LIST_RESPONSE], 'totalResults' => 0, 'itemsPerPage' => 0, 'startIndex' => 1, 'Resources' => []], self::json($response));
    }

    #[Test]
    #[TestDox('4.2 User create: the queries find nothing, POST answers 201, the filters find the user')]
    public function createsUser(): void
    {
        self::assertSame([], self::ids($this->send('GET', '/scim/v2/Users?filter=userName eq "'.self::USER_NAME.'"')));
        self::assertSame([], self::ids($this->send('GET', '/scim/v2/Users?filter=externalId eq "'.self::EXTERNAL_ID.'"')));

        $created = $this->send('POST', '/scim/v2/Users', [
            'schemas' => [Scim::USER, Scim::ENTERPRISE],
            'externalId' => self::EXTERNAL_ID,
            'userName' => self::USER_NAME,
            'active' => true,
            'emails' => [['primary' => true, 'type' => 'work', 'value' => self::EMAIL]],
            'meta' => ['resourceType' => 'User'],
            'name' => ['formatted' => 'givenName familyName', 'familyName' => 'familyName', 'givenName' => 'givenName'],
            'roles' => [],
        ]);

        $id = self::idOf($created);
        self::assertSame([201, Scim::BASE_URL.'/Users/'.$id], [$created->getStatusCode(), $created->headers->get('Location')]);
        self::assertSame([
            'schemas' => [Scim::USER],
            'id' => $id,
            'externalId' => self::EXTERNAL_ID,
            'meta' => ['resourceType' => 'User', 'created' => '2026-11-06T10:00:00Z', 'lastModified' => '2026-11-06T10:00:00Z', 'version' => $created->headers->get('ETag'), 'location' => Scim::BASE_URL.'/Users/'.$id],
            'userName' => self::USER_NAME,
            'name' => ['givenName' => 'givenName', 'familyName' => 'familyName', 'formatted' => 'givenName familyName'],
            'active' => true,
            'emails' => [['value' => self::EMAIL, 'type' => 'work', 'primary' => true]],
        ], self::json($created));
        self::assertSame([$id], self::ids($this->send('GET', '/scim/v2/Users?filter=userName eq "'.strtolower(self::USER_NAME).'"')));
        self::assertSame([$id], self::ids($this->send('GET', '/scim/v2/Users?filter=emails[type eq "work"].value eq "'.self::EMAIL.'"')));
        self::assertSame([$id], self::ids($this->send('GET', '/scim/v2/Users?filter=externalId eq "'.self::EXTERNAL_ID.'"')));
    }

    #[Test]
    #[TestDox('4.2 A second POST with the same userName answers 409 uniqueness')]
    public function rejectsDuplicateUser(): void
    {
        $this->entraUser();

        $response = $this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => self::USER_NAME, 'active' => true]);

        self::assertScimError($response, 409, 'uniqueness', 'The value of attribute "userName" is already in use.');
    }

    #[Test]
    #[TestDox('4.2 A legacy POST with root-level nulls and a misspelled URN stores no null')]
    public function ignoresNullsOnCreate(): void
    {
        $created = $this->send('POST', '/scim/v2/Users', [
            'schemas' => [Scim::USER, 'urn:ietf:params:scim:schemas:extension:enterprise:2.0User'],
            'userName' => self::USER_NAME,
            'addresses' => null,
            'phoneNumbers' => null,
            'title' => null,
            'department' => null,
            'manager' => null,
            'employeeNumber' => '701984',
            'urn:ietf:params:scim:schemas:extension:enterprise:2.0User' => ['costCenter' => '4130'],
        ]);

        self::assertSame(201, $created->getStatusCode());
        self::assertSame(['schemas' => [Scim::USER, Scim::ENTERPRISE], 'userName' => self::USER_NAME, 'active' => true, Scim::ENTERPRISE => ['employeeNumber' => '701984']], array_diff_key(self::json($created), ['id' => 0, 'meta' => 0]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function enterpriseForms(): iterable
    {
        yield 'a nested extension object' => [[Scim::ENTERPRISE => ['department' => 'Tour Operations', 'employeeNumber' => '701984']]];
        yield 'URN-qualified flat keys' => [[Scim::ENTERPRISE.':department' => 'Tour Operations', Scim::ENTERPRISE.':employeeNumber' => '701984']];
    }

    /**
     * @param array<string, mixed> $enterprise
     */
    #[Test]
    #[DataProvider('enterpriseForms')]
    #[TestDox('4.2 POST stores enterprise attributes sent as $_dataName, also when "schemas" lacks the extension URN')]
    public function createsEnterpriseAttributes(array $enterprise): void
    {
        $created = self::json($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => self::USER_NAME, ...$enterprise]));

        self::assertSame([Scim::USER, Scim::ENTERPRISE], $created['schemas'] ?? null);
        self::assertSame(['employeeNumber' => '701984', 'department' => 'Tour Operations'], $created[Scim::ENTERPRISE] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function opCases(): iterable
    {
        yield 'default style' => ['Add', 'Replace'];
        yield 'aadOptscim062020 style' => ['add', 'replace'];
    }

    #[Test]
    #[DataProvider('opCases')]
    #[TestDox('4.3 User update, $_dataName: PATCH of emails[type eq "work"].value, name.familyName, userName and nickName answers 204')]
    public function updatesUser(string $add, string $replace): void
    {
        $id = $this->entraUser();
        self::assertSame(200, $this->send('GET', '/scim/v2/Users/'.$id)->getStatusCode());

        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp(
            ['op' => $replace, 'path' => 'emails[type eq "work"].value', 'value' => 'updatedEmail@microsoft.com'],
            ['op' => $replace, 'path' => 'name.familyName', 'value' => 'updatedFamilyName'],
        )));
        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp(['op' => $replace, 'path' => 'userName', 'value' => '5b50642d-79fc-4410-9e90-4c077cdd1a59@testuser.com'])));
        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp(['op' => $add, 'path' => 'nickName', 'value' => 'Babs'])));

        $user = self::json($this->send('GET', '/scim/v2/Users/'.$id));
        self::assertSame('5b50642d-79fc-4410-9e90-4c077cdd1a59@testuser.com', $user['userName'] ?? null);
        self::assertSame(['givenName' => 'givenName', 'familyName' => 'updatedFamilyName', 'formatted' => 'givenName updatedFamilyName'], $user['name'] ?? null);
        self::assertSame('Babs', $user['nickName'] ?? null);
        self::assertSame([['value' => 'updatedEmail@microsoft.com', 'type' => 'work', 'primary' => true]], $user['emails'] ?? null);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, array<string, string>}>
     */
    public static function multiAttributeReplacements(): iterable
    {
        yield 'default style, one operation per attribute' => [
            [
                ['op' => 'Replace', 'path' => 'displayName', 'value' => 'Pvlo'],
                ['op' => 'Replace', 'path' => 'emails[type eq "work"].value', 'value' => 'TestBcwqnm@test.microsoft.com'],
                ['op' => 'Replace', 'path' => 'name.givenName', 'value' => 'Gtfd'],
                ['op' => 'Replace', 'path' => 'name.familyName', 'value' => 'Pkqf'],
                ['op' => 'Replace', 'path' => 'externalId', 'value' => 'Eqpj'],
                ['op' => 'Replace', 'path' => Scim::ENTERPRISE.':employeeNumber', 'value' => 'Eqpj'],
            ],
            ['displayName' => 'Pvlo', 'email' => 'TestBcwqnm@test.microsoft.com', 'givenName' => 'Gtfd', 'familyName' => 'Pkqf', 'externalId' => 'Eqpj', 'employeeNumber' => 'Eqpj'],
        ];
        yield 'aadOptscim062020 style, path-less with path-shaped keys' => [
            [
                ['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'TestMhvaes@test.microsoft.com'],
                ['op' => 'replace', 'value' => ['displayName' => 'Bjfe', 'name.givenName' => 'Kkom', 'name.familyName' => 'Unua', Scim::ENTERPRISE.':employeeNumber' => 'Aklq']],
            ],
            ['displayName' => 'Bjfe', 'email' => 'TestMhvaes@test.microsoft.com', 'givenName' => 'Kkom', 'familyName' => 'Unua', 'externalId' => self::EXTERNAL_ID, 'employeeNumber' => 'Aklq'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, string>      $expected
     */
    #[Test]
    #[DataProvider('multiAttributeReplacements')]
    #[TestDox('4.3 Replace several attributes, $_dataName')]
    public function replacesSeveralAttributes(array $operations, array $expected): void
    {
        $id = $this->entraUser();

        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp(...$operations)));

        $user = self::json($this->send('GET', '/scim/v2/Users/'.$id));
        self::assertSame([Scim::USER, Scim::ENTERPRISE], $user['schemas'] ?? null);
        self::assertSame($expected, [
            'displayName' => $user['displayName'] ?? null,
            'email' => $user['emails'][0]['value'] ?? null,
            'givenName' => $user['name']['givenName'] ?? null,
            'familyName' => $user['name']['familyName'] ?? null,
            'externalId' => $user['externalId'] ?? null,
            'employeeNumber' => $user[Scim::ENTERPRISE]['employeeNumber'] ?? null,
        ]);
    }

    #[Test]
    #[DataProvider('opCases')]
    #[TestDox('4.3 Filtered paths that match nothing create the slot elements, $_dataName')]
    public function createsFilteredElements(string $add, string $replace): void
    {
        $id = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => self::USER_NAME]));

        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp(
            ['op' => $add, 'path' => 'emails[type eq "work"].value', 'value' => self::EMAIL],
            ['op' => $add, 'path' => 'phoneNumbers[type eq "work"].value', 'value' => '55555555555'],
            ['op' => $replace, 'path' => 'phoneNumbers[type eq "mobile"].Value', 'value' => '+1 (555) 555-5555'],
        )));

        $user = self::json($this->send('GET', '/scim/v2/Users/'.$id));
        self::assertSame([['value' => self::EMAIL, 'type' => 'work', 'primary' => true]], $user['emails'] ?? null);
        self::assertSame([['value' => '55555555555', 'type' => 'work', 'primary' => true], ['value' => '+1 (555) 555-5555', 'type' => 'mobile']], $user['phoneNumbers'] ?? null);
    }

    /**
     * @return iterable<string, array{\Closure(string): array<string, mixed>}>
     */
    public static function managerAssignments(): iterable
    {
        yield 'M1, path without URN, array value' => [static fn (string $manager): array => ['op' => 'Add', 'path' => 'manager', 'value' => [['$ref' => 'http://.../scim/Users/'.$manager, 'value' => $manager]]]];
        yield 'M2, bare string id' => [static fn (string $manager): array => ['op' => 'Add', 'path' => Scim::ENTERPRISE.':manager', 'value' => $manager]];
        yield 'M3, object value' => [static fn (string $manager): array => ['op' => 'Add', 'path' => Scim::ENTERPRISE.':manager', 'value' => ['value' => $manager]]];
        yield 'M4, replace of manager.value' => [static fn (string $manager): array => ['op' => 'replace', 'path' => Scim::ENTERPRISE.':manager.value', 'value' => $manager]];
        yield 'M5, path-less add' => [static fn (string $manager): array => ['op' => 'Add', 'value' => [Scim::ENTERPRISE.':manager' => ['value' => $manager]]]];
        yield 'M5, path-less replace' => [static fn (string $manager): array => ['op' => 'replace', 'value' => [Scim::ENTERPRISE.':manager' => ['value' => $manager]]]];
    }

    /**
     * @param \Closure(string): array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('managerAssignments')]
    #[TestDox('4.4 Manager set with $_dataName answers 204, and GET returns the complex form')]
    public function setsManager(\Closure $operation): void
    {
        $manager = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'manager', 'displayName' => 'The Manager']));
        $id = $this->entraUser();

        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp($operation($manager))));

        $user = self::json($this->send('GET', '/scim/v2/Users/'.$id));
        self::assertSame([Scim::USER, Scim::ENTERPRISE], $user['schemas'] ?? null);
        self::assertSame(['manager' => ['value' => $manager, 'displayName' => 'The Manager', '$ref' => Scim::BASE_URL.'/Users/'.$manager]], $user[Scim::ENTERPRISE] ?? null);
    }

    #[Test]
    #[TestDox('4.4 The manager reference query with attributes=id finds the user only when the manager matches')]
    public function answersManagerQuery(): void
    {
        $manager = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'manager']));
        $id = $this->entraUser([Scim::ENTERPRISE => ['manager' => ['value' => $manager]]]);

        $match = $this->send('GET', '/scim/v2/Users?filter=id eq "'.$id.'" and manager eq "'.$manager.'"&attributes=id');

        self::assertSame([['schemas' => [Scim::USER], 'id' => $id]], self::resources($match));
        self::assertSame([], self::ids($this->send('GET', '/scim/v2/Users?filter=id eq "'.$id.'" and manager eq "26118915-6090-4610-87e4-49d8ca9f808d"&attributes=id')));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function managerRemovals(): iterable
    {
        yield 'M6, remove' => [['op' => 'remove', 'path' => Scim::ENTERPRISE.':manager']];
        yield 'M7, replace with ""' => [['op' => 'replace', 'path' => Scim::ENTERPRISE.':manager', 'value' => '']];
        yield 'replace with null' => [['op' => 'replace', 'path' => Scim::ENTERPRISE.':manager', 'value' => null]];
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('managerRemovals')]
    #[TestDox('4.4 Manager clear with $_dataName answers 204 and removes the manager')]
    public function clearsManager(array $operation): void
    {
        $manager = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'manager']));
        $id = $this->entraUser([Scim::ENTERPRISE => ['manager' => ['value' => $manager]]]);

        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp($operation)));

        $user = self::json($this->send('GET', '/scim/v2/Users/'.$id));
        self::assertSame([Scim::USER], $user['schemas'] ?? null);
        self::assertArrayNotHasKey(Scim::ENTERPRISE, $user);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function activeToggles(): iterable
    {
        yield 'default style, "False" and "True"' => [
            self::patchOp(['op' => 'Replace', 'path' => 'active', 'value' => 'False']),
            self::patchOp(['op' => 'Replace', 'path' => 'active', 'value' => 'True']),
        ];
        yield 'aadOptscim062020 style, booleans' => [
            self::patchOp(['op' => 'replace', 'path' => 'active', 'value' => false]),
            self::patchOp(['op' => 'replace', 'path' => 'active', 'value' => true]),
        ];
        yield 'tutorial style, Operations before schemas' => [
            ['Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => false]], 'schemas' => [Scim::PATCH_OP]],
            ['Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => true]], 'schemas' => [Scim::PATCH_OP]],
        ];
    }

    /**
     * @param array<string, mixed> $disable
     * @param array<string, mixed> $restore
     */
    #[Test]
    #[DataProvider('activeToggles')]
    #[TestDox('4.5 Disable and restore, $_dataName: the inactive user stays visible by id and by filter')]
    public function disablesAndRestoresUser(array $disable, array $restore): void
    {
        $id = $this->entraUser();

        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, $disable));
        self::assertFalse(self::json($this->send('GET', '/scim/v2/Users/'.$id))['active'] ?? null);
        self::assertSame([false], array_column(self::resources($this->send('GET', '/scim/v2/Users?filter=userName eq "'.self::USER_NAME.'"')), 'active'));

        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, $restore));
        self::assertTrue(self::json($this->send('GET', '/scim/v2/Users/'.$id))['active'] ?? null);
    }

    #[Test]
    #[TestDox('4.6 Hard delete answers 204; then GET and DELETE answer 404 and the filter finds nothing')]
    public function deletesUser(): void
    {
        $id = $this->entraUser();

        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp(['op' => 'replace', 'path' => 'active', 'value' => false])));
        self::assertNoContent($this->send('DELETE', '/scim/v2/Users/'.$id));

        self::assertScimError($this->send('GET', '/scim/v2/Users/'.$id), 404, null, 'Resource '.$id.' not found.');
        self::assertScimError($this->send('DELETE', '/scim/v2/Users/'.$id), 404, null, 'Resource '.$id.' not found.');
        self::assertSame([], self::ids($this->send('GET', '/scim/v2/Users?filter=userName eq "'.self::USER_NAME.'"')));
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function nullUpdates(): iterable
    {
        foreach (['""' => '', 'null' => null] as $name => $empty) {
            yield 'with '.$name.', one operation per path' => [[
                ['op' => 'replace', 'path' => 'title', 'value' => $empty],
                ['op' => 'replace', 'path' => 'nickName', 'value' => $empty],
                ['op' => 'replace', 'path' => Scim::ENTERPRISE.':department', 'value' => $empty],
            ]];
            yield 'with '.$name.', path-less' => [[
                ['op' => 'replace', 'value' => ['title' => $empty, 'nickName' => $empty, Scim::ENTERPRISE.':department' => $empty]],
            ]];
        }
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    #[Test]
    #[DataProvider('nullUpdates')]
    #[TestDox('10 SCIM_Null_Update_Test $_dataName clears the attributes')]
    public function clearsAttributes(array $operations): void
    {
        $id = $this->entraUser(['title' => 'Tour Guide', 'nickName' => 'Babs', Scim::ENTERPRISE => ['department' => 'Tour Operations']]);

        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp(...$operations)));

        $user = self::json($this->send('GET', '/scim/v2/Users/'.$id));
        self::assertSame([Scim::USER], $user['schemas'] ?? null);
        self::assertSame([], array_intersect_key($user, ['title' => 0, 'nickName' => 0, Scim::ENTERPRISE => 0]));
    }

    #[Test]
    #[TestDox('4.7 Group create: the displayName query finds nothing, POST answers 201, a duplicate name 409')]
    public function createsGroup(): void
    {
        self::assertSame([], self::ids($this->send('GET', '/scim/v2/Groups?excludedAttributes=members&filter=displayName eq "displayName"')));

        $created = $this->send('POST', '/scim/v2/Groups', [
            'schemas' => [Scim::GROUP, 'http://schemas.microsoft.com/2006/11/ResourceManagement/ADSCIM/2.0/Group'],
            'externalId' => '8aa1a0c0-c4c3-4bc0-b4a5-2ef676900159',
            'displayName' => 'displayName',
            'meta' => ['resourceType' => 'Group'],
        ]);

        $id = self::idOf($created);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame([
            'schemas' => [Scim::GROUP],
            'id' => $id,
            'externalId' => '8aa1a0c0-c4c3-4bc0-b4a5-2ef676900159',
            'meta' => ['resourceType' => 'Group', 'created' => '2026-11-06T10:00:00Z', 'lastModified' => '2026-11-06T10:00:00Z', 'version' => $created->headers->get('ETag'), 'location' => Scim::BASE_URL.'/Groups/'.$id],
            'displayName' => 'displayName',
        ], self::json($created));
        self::assertSame([$id], self::ids($this->send('GET', '/scim/v2/Groups?excludedAttributes=members&filter=displayName eq "displayName"')));
        self::assertSame([$id], self::ids($this->send('GET', '/scim/v2/Groups?filter=externalId eq "8aa1a0c0-c4c3-4bc0-b4a5-2ef676900159"&excludedAttributes=members')));
        self::assertScimError($this->send('POST', '/scim/v2/Groups', ['schemas' => [Scim::GROUP], 'displayName' => 'DISPLAYNAME']), 409, 'uniqueness', 'The value of attribute "displayName" is already in use.');
    }

    #[Test]
    #[TestDox('4.8 Member add with "$ref": null answers 204; adding the same member again changes nothing')]
    public function addsMembers(): void
    {
        $member = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen']));
        $other = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'jsmith']));
        $group = $this->group();
        $add = self::patchOp(['op' => 'Add', 'path' => 'members', 'value' => [['$ref' => null, 'value' => $member]]]);

        $added = $this->send('PATCH', '/scim/v2/Groups/'.$group, $add);
        $repeated = $this->send('PATCH', '/scim/v2/Groups/'.$group, $add);
        self::assertNoContent($this->send('PATCH', '/scim/v2/Groups/'.$group, self::patchOp(['op' => 'Add', 'path' => 'members', 'value' => [['value' => $member], ['value' => $other]]])));

        self::assertNoContent($added);
        self::assertNoContent($repeated);
        self::assertSame($added->headers->get('ETag'), $repeated->headers->get('ETag'));
        self::assertEqualsCanonicalizing([$member, $other], array_column((array) (self::json($this->send('GET', '/scim/v2/Groups/'.$group))['members'] ?? []), 'value'));
    }

    /**
     * @return iterable<string, array{\Closure(string): array<string, mixed>}>
     */
    public static function memberRemovals(): iterable
    {
        yield 'default style with "$ref": null' => [static fn (string $member): array => ['op' => 'Remove', 'path' => 'members', 'value' => [['$ref' => null, 'value' => $member]]]];
        yield 'default style without "$ref"' => [static fn (string $member): array => ['op' => 'Remove', 'path' => 'members', 'value' => [['value' => $member]]]];
        yield 'aadOptscim062020 style, value filter' => [static fn (string $member): array => ['op' => 'remove', 'path' => 'members[value eq "'.$member.'"]']];
    }

    /**
     * @param \Closure(string): array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('memberRemovals')]
    #[TestDox('4.9 Member remove, $_dataName, removes only that member and answers 204')]
    public function removesMember(\Closure $operation): void
    {
        $member = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen']));
        $other = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'jsmith']));
        $group = $this->group([['value' => $member], ['value' => $other]]);

        $removed = $this->send('PATCH', '/scim/v2/Groups/'.$group, self::patchOp($operation($member)));
        $again = $this->send('PATCH', '/scim/v2/Groups/'.$group, self::patchOp($operation($member)));

        self::assertNoContent($removed);
        self::assertNoContent($again);
        self::assertSame($removed->headers->get('ETag'), $again->headers->get('ETag'));
        self::assertSame([$other], array_column((array) (self::json($this->send('GET', '/scim/v2/Groups/'.$group))['members'] ?? []), 'value'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function renames(): iterable
    {
        yield 'default style' => [['op' => 'Replace', 'path' => 'displayName', 'value' => '1879db59-3bdf-4490-ad68-ab880a269474updatedDisplayName']];
        yield 'aadOptscim062020 path-less style' => [['op' => 'replace', 'value' => ['displayName' => '1879db59-3bdf-4490-ad68-ab880a269474updatedDisplayName']]];
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('renames')]
    #[TestDox('4.10 Group rename, $_dataName: GET without members, then PATCH answers 204 and keeps the members')]
    public function renamesGroup(array $operation): void
    {
        $member = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen']));
        $group = $this->group([['value' => $member]], ['externalId' => '60f1bb27-2e1e-402d-bcc4-ec999564a194']);

        self::assertSame(['schemas', 'id', 'externalId', 'meta', 'displayName'], array_keys(self::json($this->send('GET', '/scim/v2/Groups/'.$group.'?excludedAttributes=members'))));
        self::assertNoContent($this->send('PATCH', '/scim/v2/Groups/'.$group, self::patchOp($operation)));

        $renamed = self::json($this->send('GET', '/scim/v2/Groups/'.$group));
        self::assertSame(['1879db59-3bdf-4490-ad68-ab880a269474updatedDisplayName', [$member]], [$renamed['displayName'] ?? null, array_column((array) ($renamed['members'] ?? []), 'value')]);
    }

    #[Test]
    #[TestDox('4.11 The membership check finds the group, with only its id, only when the user is a member')]
    public function answersMembershipCheck(): void
    {
        $member = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen']));
        $other = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'jsmith']));
        $group = $this->group([['value' => $member]]);

        $found = $this->send('GET', '/scim/v2/Groups?filter=id eq "'.$group.'" and members eq "'.$member.'"&attributes=id');

        self::assertSame([['schemas' => [Scim::GROUP], 'id' => $group]], self::resources($found));
        self::assertSame([], self::ids($this->send('GET', '/scim/v2/Groups?filter=id eq "'.$group.'" and members eq "'.$other.'"&attributes=id')));
    }

    #[Test]
    #[TestDox('4.12 Group delete answers 204; the displayName query then finds nothing and the members stay')]
    public function deletesGroup(): void
    {
        $member = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen']));
        $group = $this->group([['value' => $member]]);

        self::assertNoContent($this->send('DELETE', '/scim/v2/Groups/'.$group));

        self::assertSame([], self::ids($this->send('GET', '/scim/v2/Groups?excludedAttributes=members&filter=displayName eq "displayName"')));
        self::assertSame(200, $this->send('GET', '/scim/v2/Users/'.$member)->getStatusCode());
    }

    #[Test]
    #[TestDox('4.14 /Schemas lists the User, EnterpriseUser and Group schemas without null values')]
    public function discoversSchemas(): void
    {
        $response = $this->send('GET', '/scim/v2/Schemas');

        self::assertSame([Scim::GROUP, Scim::USER, Scim::ENTERPRISE], array_values(array_intersect(array_column(self::resources($response), 'id'), [Scim::USER, Scim::ENTERPRISE, Scim::GROUP])));
        self::assertStringNotContainsString('null', (string) $response->getContent());
        self::assertStringContainsString('"mutability":"readWrite"', (string) $response->getContent());
    }

    #[Test]
    #[TestDox('4.15 SCIM_User_Pagination_Test: pages of 5 echo startIndex, report the total and cover every user once')]
    public function paginatesUsers(): void
    {
        $created = array_map(fn (int $i): string => self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'user'.$i])), range(1, 12));
        $startIndexes = [];
        $ids = [];
        $startIndex = 1;

        do {
            $page = self::json($this->send('GET', '/scim/v2/Users?startIndex='.$startIndex.'&count=5'));
            $resources = \is_array($page['Resources'] ?? null) ? $page['Resources'] : [];
            $startIndexes[] = $page['startIndex'] ?? null;
            $ids = [...$ids, ...array_column($resources, 'id')];
            $startIndex = (int) ($page['startIndex'] ?? 0) + \count($resources);
        } while ($startIndex <= ($page['totalResults'] ?? 0));

        self::assertSame([1, 6, 11], $startIndexes);
        self::assertSame($created, $ids);
        self::assertSame([$created[0]], self::ids($this->send('GET', '/scim/v2/Users?startIndex=1&count=1')));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function entraUser(array $extra = []): string
    {
        $response = $this->send('POST', '/scim/v2/Users', [
            'schemas' => [Scim::USER, Scim::ENTERPRISE],
            'externalId' => self::EXTERNAL_ID,
            'userName' => self::USER_NAME,
            'name' => ['formatted' => 'givenName familyName', 'familyName' => 'familyName', 'givenName' => 'givenName'],
            'active' => true,
            'emails' => [['value' => self::EMAIL, 'type' => 'work', 'primary' => true]],
            ...$extra,
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return self::idOf($response);
    }

    /**
     * @param list<array<string, string>> $members
     * @param array<string, string>       $extra
     */
    private function group(array $members = [], array $extra = []): string
    {
        $response = $this->send('POST', '/scim/v2/Groups', ['schemas' => [Scim::GROUP], 'displayName' => 'displayName', 'members' => $members, ...$extra]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return self::idOf($response);
    }
}
