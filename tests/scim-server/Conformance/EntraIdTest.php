<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Tests\Conformance\Fixtures\IdpClient;

/**
 * Request sequences of Microsoft Entra ID and its SCIM Validator, default style and aadOptscim062020 style. Entra sends
 * no User-Agent and no documented Accept.
 */
final class EntraIdTest extends KernelTestCase
{
    use IdpClient;
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const array HEADERS = ['Authorization' => 'Bearer secret-token'];
    private const array BODY_HEADERS = ['Content-Type' => 'application/scim+json'];
    private const string GUID = '5f8c2c9e-3d4b-4a8e-9b1a-1d2e3f4a5b6c';
    private const string USER_NAME = 'Test_User_00aa00aa-bb11-cc22-dd33-44ee44ee44ee';
    private const string EMAIL = 'Test_User_11bb11bb-cc22-dd33-ee44-55ff55ff55ff@testuser.com';
    private const string MANAGER = '00aa00aa-bb11-cc22-dd33-44ee44ee44ee';
    private const string MEMBER = 'f648f8d5ea4e4cd38e9c';

    /**
     * @return iterable<string, array{string}>
     */
    public static function connectionQueries(): iterable
    {
        yield 'as documented' => ['/Users?filter=userName eq "'.self::GUID.'"'];
        yield 'spaces as %20, quotes as %22' => ['/Users?filter=userName%20eq%20%22'.self::GUID.'%22'];
        yield 'spaces as +' => ['/Users?filter=userName+eq+%22'.self::GUID.'%22'];
        yield 'a Tenant URL with a trailing slash' => ['//Users?filter=userName eq "'.self::GUID.'"'];
        yield 'a Tenant URL with ?aadOptscim062020' => ['/Users?aadOptscim062020&filter=userName eq "'.self::GUID.'"'];
        yield 'the group request by analogy' => ['/Groups?excludedAttributes=members&filter=displayName eq "'.self::GUID.'"'];
    }

    #[Test]
    #[DataProvider('connectionQueries')]
    #[TestDox('Test Connection answers 200 with an empty ListResponse for $_dataName')]
    public function answersTestConnection(string $uri): void
    {
        $this->seed([self::storedUser('u1', 'bjensen')], [self::storedGroup('g1', 'Tour Guides')]);

        self::assertEmptyListResponse($this->request('GET', $uri));
    }

    #[Test]
    #[TestDox('User create: userName and externalId queries find nothing, POST answers 201 with the full user')]
    public function createsUser(): void
    {
        $this->seed();

        self::assertEmptyListResponse($this->request('GET', '/Users?filter=userName eq "'.self::USER_NAME.'"'));
        self::assertEmptyListResponse($this->request('GET', '/Users?filter=externalId eq "0a21f0f2-8d2a-4f8e-bf98-7363c4aed4ef"'));
        $created = $this->request('POST', '/Users', [
            'schemas' => [self::USER, self::ENTERPRISE],
            'externalId' => '0a21f0f2-8d2a-4f8e-bf98-7363c4aed4ef',
            'userName' => self::USER_NAME,
            'active' => true,
            'emails' => [['primary' => true, 'type' => 'work', 'value' => self::EMAIL]],
            'meta' => ['resourceType' => 'User'],
            'name' => ['formatted' => 'givenName familyName', 'familyName' => 'familyName', 'givenName' => 'givenName'],
            'roles' => [],
        ]);

        $id = self::idOf($created);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('application/scim+json', $created->headers->get('Content-Type'));
        self::assertSame(self::BASE_URL.'/Users/'.$id, $created->headers->get('Location'));
        self::assertSame([
            'schemas' => [self::USER],
            'id' => $id,
            'meta' => ['resourceType' => 'User', 'created' => '2026-11-05T10:00:00Z', 'lastModified' => '2026-11-05T10:00:00Z', 'version' => 'W/"1"', 'location' => self::BASE_URL.'/Users/'.$id],
            'externalId' => '0a21f0f2-8d2a-4f8e-bf98-7363c4aed4ef',
            'userName' => self::USER_NAME,
            'name' => ['formatted' => 'givenName familyName', 'familyName' => 'familyName', 'givenName' => 'givenName'],
            'active' => true,
            'emails' => [['primary' => true, 'type' => 'work', 'value' => self::EMAIL]],
        ], self::json($created));
        self::assertSame([$id], self::ids($this->request('GET', '/Users?filter=userName eq "'.strtolower(self::USER_NAME).'"')));
        self::assertSame([$id], self::ids($this->request('GET', '/Users?filter=emails[type eq "work"].value eq "'.self::EMAIL.'"')));
    }

    #[Test]
    #[TestDox('A second POST with the same userName answers 409 uniqueness (SCIM Validator)')]
    public function rejectsDuplicateUser(): void
    {
        $this->seed([$this->entraUser('2441309d85324e7793ae')]);

        $response = $this->request('POST', '/Users', ['schemas' => [self::USER], 'userName' => self::USER_NAME, 'active' => true]);

        self::assertScimError($response, 409, 'uniqueness', 'The value of attribute "userName" is already in use.');
    }

    #[Test]
    #[TestDox('A legacy POST with root-level nulls, unqualified enterprise attributes and a misspelled URN stores no null')]
    public function ignoresNullsOnCreate(): void
    {
        $this->seed();

        $created = $this->request('POST', '/Users', [
            'schemas' => [self::USER, 'urn:ietf:params:scim:schemas:extension:enterprise:2.0User'],
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
        self::assertSame(['schemas' => [self::USER, self::ENTERPRISE], 'userName' => self::USER_NAME, self::ENTERPRISE => ['employeeNumber' => '701984']], array_diff_key(self::json($created), ['id' => 0, 'meta' => 0]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function enterpriseForms(): iterable
    {
        yield 'a nested extension object' => [[self::ENTERPRISE => ['department' => 'Tour Operations', 'employeeNumber' => '701984']]];
        yield 'URN-qualified flat keys' => [[self::ENTERPRISE.':department' => 'Tour Operations', self::ENTERPRISE.':employeeNumber' => '701984']];
    }

    /**
     * @param array<string, mixed> $enterprise
     */
    #[Test]
    #[DataProvider('enterpriseForms')]
    #[TestDox('POST stores enterprise attributes sent as $_dataName, also when "schemas" lacks the extension URN')]
    public function createsEnterpriseAttributes(array $enterprise): void
    {
        $this->seed();

        $created = self::json($this->request('POST', '/Users', ['schemas' => [self::USER], 'userName' => self::USER_NAME, ...$enterprise]));

        self::assertSame([self::USER, self::ENTERPRISE], $created['schemas'] ?? null);
        self::assertSame(['employeeNumber' => '701984', 'department' => 'Tour Operations'], $created[self::ENTERPRISE] ?? null);
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
    #[TestDox('User update, $_dataName: GET, then PATCH of emails[type eq "work"].value, name.familyName, userName and nickName answers 204')]
    public function updatesUser(string $add, string $replace): void
    {
        $this->seed([$this->entraUser('6764549bef60420686bc')]);
        self::assertSame(200, $this->request('GET', '/Users/6764549bef60420686bc')->getStatusCode());

        self::assertNoContent($this->request('PATCH', '/Users/6764549bef60420686bc', self::patchOp(
            ['op' => $replace, 'path' => 'emails[type eq "work"].value', 'value' => 'updatedEmail@microsoft.com'],
            ['op' => $replace, 'path' => 'name.familyName', 'value' => 'updatedFamilyName'],
        )));
        self::assertNoContent($this->request('PATCH', '/Users/6764549bef60420686bc', self::patchOp(['op' => $replace, 'path' => 'userName', 'value' => '5b50642d-79fc-4410-9e90-4c077cdd1a59@testuser.com'])));
        self::assertNoContent($this->request('PATCH', '/Users/6764549bef60420686bc', self::patchOp(['op' => $add, 'path' => 'nickName', 'value' => 'Babs'])));

        $user = self::json($this->request('GET', '/Users/6764549bef60420686bc'));
        self::assertSame('5b50642d-79fc-4410-9e90-4c077cdd1a59@testuser.com', $user['userName'] ?? null);
        self::assertSame(['formatted' => 'givenName familyName', 'familyName' => 'updatedFamilyName', 'givenName' => 'givenName'], $user['name'] ?? null);
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
                ['op' => 'Replace', 'path' => self::ENTERPRISE.':employeeNumber', 'value' => 'Eqpj'],
            ],
            ['displayName' => 'Pvlo', 'email' => 'TestBcwqnm@test.microsoft.com', 'givenName' => 'Gtfd', 'familyName' => 'Pkqf', 'externalId' => 'Eqpj', 'employeeNumber' => 'Eqpj'],
        ];
        yield 'aadOptscim062020 style, path-less with path-shaped keys' => [
            [
                ['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'TestMhvaes@test.microsoft.com'],
                ['op' => 'replace', 'value' => ['displayName' => 'Bjfe', 'name.givenName' => 'Kkom', 'name.familyName' => 'Unua', self::ENTERPRISE.':employeeNumber' => 'Aklq']],
            ],
            ['displayName' => 'Bjfe', 'email' => 'TestMhvaes@test.microsoft.com', 'givenName' => 'Kkom', 'familyName' => 'Unua', 'externalId' => '0a21f0f2-8d2a-4f8e-bf98-7363c4aed4ef', 'employeeNumber' => 'Aklq'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, string>      $expected
     */
    #[Test]
    #[DataProvider('multiAttributeReplacements')]
    #[TestDox('Replace several attributes, $_dataName')]
    public function replacesSeveralAttributes(array $operations, array $expected): void
    {
        $this->seed([$this->entraUser('6764549bef60420686bc')]);

        self::assertNoContent($this->request('PATCH', '/Users/6764549bef60420686bc', self::patchOp(...$operations)));

        $user = self::json($this->request('GET', '/Users/6764549bef60420686bc'));
        self::assertSame([self::USER, self::ENTERPRISE], $user['schemas'] ?? null);
        self::assertSame($expected, [
            'displayName' => $user['displayName'] ?? null,
            'email' => $user['emails'][0]['value'] ?? null,
            'givenName' => $user['name']['givenName'] ?? null,
            'familyName' => $user['name']['familyName'] ?? null,
            'externalId' => $user['externalId'] ?? null,
            'employeeNumber' => $user[self::ENTERPRISE]['employeeNumber'] ?? null,
        ]);
    }

    #[Test]
    #[DataProvider('opCases')]
    #[TestDox('Filtered paths that match nothing create the element from the eq terms, $_dataName (erratum 8097)')]
    public function createsFilteredElements(string $add, string $replace): void
    {
        $this->seed([self::storedUser('6764549bef60420686bc', self::USER_NAME)]);

        self::assertNoContent($this->request('PATCH', '/Users/6764549bef60420686bc', self::patchOp(
            ['op' => $add, 'path' => 'emails[type eq "work"].value', 'value' => self::EMAIL],
            ['op' => $replace, 'path' => 'emails[type eq "other"].Value', 'value' => 'smtp:babs@contoso.com'],
            ['op' => $add, 'path' => 'phoneNumbers[type eq "work"].value', 'value' => '55555555555'],
            ['op' => $add, 'path' => 'phoneNumbers[type eq "mobile"].value', 'value' => '+1 (555) 555-5555'],
            ['op' => $add, 'path' => 'addresses[type eq "work"].streetAddress', 'value' => '1 Microsoft Way'],
            ['op' => $replace, 'path' => 'addresses[type eq "work"].postalCode', 'value' => '98052'],
            ['op' => $add, 'path' => 'addresses[type eq "other"].Formatted', 'value' => 'Building 92'],
        )));

        $user = self::json($this->request('GET', '/Users/6764549bef60420686bc'));
        self::assertSame([['type' => 'work', 'value' => self::EMAIL], ['type' => 'other', 'value' => 'smtp:babs@contoso.com']], $user['emails'] ?? null);
        self::assertSame([['type' => 'work', 'value' => '55555555555'], ['type' => 'mobile', 'value' => '+1 (555) 555-5555']], $user['phoneNumbers'] ?? null);
        self::assertSame([['type' => 'work', 'streetAddress' => '1 Microsoft Way', 'postalCode' => '98052'], ['type' => 'other', 'formatted' => 'Building 92']], $user['addresses'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function managerAssignments(): iterable
    {
        $ref = 'http://.../scim/Users/'.self::MANAGER;

        yield 'path without URN, array value' => [['op' => 'Add', 'path' => 'manager', 'value' => [['$ref' => $ref, 'value' => self::MANAGER]]], ['$ref' => $ref, 'value' => self::MANAGER]];
        yield 'bare string id' => [['op' => 'Add', 'path' => self::ENTERPRISE.':manager', 'value' => self::MANAGER], ['value' => self::MANAGER, '$ref' => self::BASE_URL.'/Users/'.self::MANAGER]];
        yield 'object value' => [['op' => 'Add', 'path' => self::ENTERPRISE.':manager', 'value' => ['value' => self::MANAGER]], ['value' => self::MANAGER, '$ref' => self::BASE_URL.'/Users/'.self::MANAGER]];
        yield 'replace of manager.value' => [['op' => 'replace', 'path' => self::ENTERPRISE.':manager.value', 'value' => self::MANAGER], ['value' => self::MANAGER, '$ref' => self::BASE_URL.'/Users/'.self::MANAGER]];
        yield 'path-less add' => [['op' => 'Add', 'value' => [self::ENTERPRISE.':manager' => ['value' => self::MANAGER]]], ['value' => self::MANAGER, '$ref' => self::BASE_URL.'/Users/'.self::MANAGER]];
        yield 'path-less replace' => [['op' => 'replace', 'value' => [self::ENTERPRISE.':manager' => ['value' => self::MANAGER]]], ['value' => self::MANAGER, '$ref' => self::BASE_URL.'/Users/'.self::MANAGER]];
    }

    /**
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('managerAssignments')]
    #[TestDox('Manager set with $_dataName answers 204, and GET returns the complex form')]
    public function setsManager(array $operation, array $expected): void
    {
        $this->seed([self::storedUser('a0a0a0a0-bbbb-cccc-dddd-e1e1e1e1e1e1', self::USER_NAME)]);

        self::assertNoContent($this->request('PATCH', '/Users/a0a0a0a0-bbbb-cccc-dddd-e1e1e1e1e1e1', self::patchOp($operation)));

        $user = self::json($this->request('GET', '/Users/a0a0a0a0-bbbb-cccc-dddd-e1e1e1e1e1e1'));
        self::assertSame([self::USER, self::ENTERPRISE], $user['schemas'] ?? null);
        self::assertSame(['manager' => $expected], $user[self::ENTERPRISE] ?? null);
    }

    #[Test]
    #[TestDox('The manager reference query with attributes=id finds the user only when the manager matches')]
    public function answersManagerQuery(): void
    {
        $this->seed([self::storedUser('a0a0a0a0-bbbb-cccc-dddd-e1e1e1e1e1e1', self::USER_NAME, ['schemas' => [self::USER, self::ENTERPRISE], self::ENTERPRISE => ['manager' => ['value' => self::MANAGER]]])]);

        $match = $this->request('GET', '/Users?filter=id eq "a0a0a0a0-bbbb-cccc-dddd-e1e1e1e1e1e1" and manager eq "'.self::MANAGER.'"&attributes=id');

        self::assertSame([['schemas' => [self::USER, self::ENTERPRISE], 'id' => 'a0a0a0a0-bbbb-cccc-dddd-e1e1e1e1e1e1']], self::resources($match));
        self::assertEmptyListResponse($this->request('GET', '/Users?filter=id eq "a0a0a0a0-bbbb-cccc-dddd-e1e1e1e1e1e1" and manager eq "26118915-6090-4610-87e4-49d8ca9f808d"&attributes=id'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function managerRemovals(): iterable
    {
        yield 'remove' => [['op' => 'remove', 'path' => self::ENTERPRISE.':manager']];
        yield 'replace with ""' => [['op' => 'replace', 'path' => self::ENTERPRISE.':manager', 'value' => '']];
        yield 'replace with null (SCIM_Null_Update_Test)' => [['op' => 'replace', 'path' => self::ENTERPRISE.':manager', 'value' => null]];
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('managerRemovals')]
    #[TestDox('Manager clear with $_dataName answers 204 and removes the manager')]
    public function clearsManager(array $operation): void
    {
        $this->seed([self::storedUser('a0a0a0a0-bbbb-cccc-dddd-e1e1e1e1e1e1', self::USER_NAME, ['schemas' => [self::USER, self::ENTERPRISE], self::ENTERPRISE => ['manager' => ['value' => self::MANAGER]]])]);

        self::assertNoContent($this->request('PATCH', '/Users/a0a0a0a0-bbbb-cccc-dddd-e1e1e1e1e1e1', self::patchOp($operation)));

        $user = self::json($this->request('GET', '/Users/a0a0a0a0-bbbb-cccc-dddd-e1e1e1e1e1e1'));
        self::assertSame([self::USER], $user['schemas'] ?? null);
        self::assertArrayNotHasKey(self::ENTERPRISE, $user);
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
            ['Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => false]], 'schemas' => [self::PATCH_OP]],
            ['Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => true]], 'schemas' => [self::PATCH_OP]],
        ];
    }

    /**
     * @param array<string, mixed> $disable
     * @param array<string, mixed> $restore
     */
    #[Test]
    #[DataProvider('activeToggles')]
    #[TestDox('Disable and restore, $_dataName: the inactive user stays visible by id and by filter')]
    public function disablesAndRestoresUser(array $disable, array $restore): void
    {
        $this->seed([$this->entraUser('6764549bef60420686bc')]);

        self::assertSame(200, $this->request('GET', '/Users/6764549bef60420686bc')->getStatusCode());
        self::assertNoContent($this->request('PATCH', '/Users/6764549bef60420686bc', $disable));

        self::assertFalse(self::json($this->request('GET', '/Users/6764549bef60420686bc'))['active'] ?? null);
        self::assertSame([false], array_column(self::resources($this->request('GET', '/Users?filter=userName eq "'.self::USER_NAME.'"')), 'active'));

        self::assertNoContent($this->request('PATCH', '/Users/6764549bef60420686bc', $restore));
        self::assertTrue(self::json($this->request('GET', '/Users/6764549bef60420686bc'))['active'] ?? null);
    }

    #[Test]
    #[TestDox('Hard delete answers 204; afterwards GET and DELETE answer 404 and the filter finds nothing')]
    public function deletesUser(): void
    {
        $this->seed([$this->entraUser('5171a35d82074e068ce2')]);

        self::assertNoContent($this->request('DELETE', '/Users/5171a35d82074e068ce2'));

        self::assertScimError($this->request('GET', '/Users/5171a35d82074e068ce2'), 404, null, 'Resource 5171a35d82074e068ce2 not found.');
        self::assertScimError($this->request('DELETE', '/Users/5171a35d82074e068ce2'), 404, null, 'Resource 5171a35d82074e068ce2 not found.');
        self::assertEmptyListResponse($this->request('GET', '/Users?filter=userName eq "'.self::USER_NAME.'"'));
    }

    #[Test]
    #[TestDox('Validator cleanup: replace active false, then DELETE')]
    public function cleansUp(): void
    {
        $this->seed([$this->entraUser('5171a35d82074e068ce2')]);

        self::assertNoContent($this->request('PATCH', '/Users/5171a35d82074e068ce2', self::patchOp(['op' => 'replace', 'path' => 'active', 'value' => false])));
        self::assertNoContent($this->request('DELETE', '/Users/5171a35d82074e068ce2'));
        self::assertNull($this->users->get('5171a35d82074e068ce2'));
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function nullUpdates(): iterable
    {
        foreach (['""' => '', 'null' => null] as $name => $empty) {
            yield 'phase with '.$name.', one operation per path' => [[
                ['op' => 'replace', 'path' => 'title', 'value' => $empty],
                ['op' => 'replace', 'path' => 'nickName', 'value' => $empty],
                ['op' => 'replace', 'path' => self::ENTERPRISE.':department', 'value' => $empty],
            ]];
            yield 'phase with '.$name.', path-less' => [[
                ['op' => 'replace', 'value' => ['title' => $empty, 'nickName' => $empty, self::ENTERPRISE.':department' => $empty]],
            ]];
        }
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    #[Test]
    #[DataProvider('nullUpdates')]
    #[TestDox('SCIM_Null_Update_Test $_dataName clears the attributes')]
    public function clearsAttributes(array $operations): void
    {
        $this->seed([$this->entraUser('6764549bef60420686bc', ['title' => 'Tour Guide', 'nickName' => 'Babs', self::ENTERPRISE => ['department' => 'Tour Operations']])]);

        self::assertNoContent($this->request('PATCH', '/Users/6764549bef60420686bc', self::patchOp(...$operations)));

        $user = self::json($this->request('GET', '/Users/6764549bef60420686bc'));
        self::assertSame([self::USER], $user['schemas'] ?? null);
        self::assertSame([], array_intersect_key($user, ['title' => 0, 'nickName' => 0, self::ENTERPRISE => 0]));
    }

    #[Test]
    #[TestDox('Group create: the displayName query finds nothing, POST answers 201')]
    public function createsGroup(): void
    {
        $this->seed();

        self::assertEmptyListResponse($this->request('GET', '/Groups?excludedAttributes=members&filter=displayName eq "displayName"'));
        $created = $this->request('POST', '/Groups', [
            'schemas' => [self::GROUP, 'http://schemas.microsoft.com/2006/11/ResourceManagement/ADSCIM/2.0/Group'],
            'externalId' => '8aa1a0c0-c4c3-4bc0-b4a5-2ef676900159',
            'displayName' => 'displayName',
            'meta' => ['resourceType' => 'Group'],
        ]);

        $id = self::idOf($created);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame([
            'schemas' => [self::GROUP],
            'id' => $id,
            'meta' => ['resourceType' => 'Group', 'created' => '2026-11-05T10:00:00Z', 'lastModified' => '2026-11-05T10:00:00Z', 'version' => 'W/"1"', 'location' => self::BASE_URL.'/Groups/'.$id],
            'externalId' => '8aa1a0c0-c4c3-4bc0-b4a5-2ef676900159',
            'displayName' => 'displayName',
        ], self::json($created));
        self::assertSame([$id], self::ids($this->request('GET', '/Groups?excludedAttributes=members&filter=displayName eq "displayName"')));
        self::assertSame([$id], self::ids($this->request('GET', '/Groups?filter=externalId eq "8aa1a0c0-c4c3-4bc0-b4a5-2ef676900159"&excludedAttributes=members')));
    }

    #[Test]
    #[TestDox('A second group with the same displayName answers 409 uniqueness')]
    public function rejectsDuplicateGroupName(): void
    {
        $this->seed(groups: [self::storedGroup('8c601452cc934a9ebef9', 'displayName')]);

        self::assertScimError($this->request('POST', '/Groups', ['schemas' => [self::GROUP], 'displayName' => 'displayName']), 409, 'uniqueness', 'The value of attribute "displayName" is already in use.');
    }

    #[Test]
    #[TestDox('Member add with "$ref": null answers 204; adding the same member again changes nothing')]
    public function addsMembers(): void
    {
        $this->seed([self::storedUser(self::MEMBER, 'bjensen'), self::storedUser('u2', 'jsmith')], [self::storedGroup('a99962b9f99d4c4fac67', 'displayName')]);
        $add = self::patchOp(['op' => 'Add', 'path' => 'members', 'value' => [['$ref' => null, 'value' => self::MEMBER]]]);

        self::assertNoContent($this->request('PATCH', '/Groups/a99962b9f99d4c4fac67', $add));
        $repeated = $this->request('PATCH', '/Groups/a99962b9f99d4c4fac67', $add);
        self::assertNoContent($this->request('PATCH', '/Groups/a99962b9f99d4c4fac67', self::patchOp(['op' => 'Add', 'path' => 'members', 'value' => [['value' => self::MEMBER], ['value' => 'u2']]])));

        self::assertNoContent($repeated);
        self::assertSame('W/"2"', $repeated->headers->get('ETag'));
        self::assertSame([['value' => self::MEMBER], ['value' => 'u2']], self::json($this->request('GET', '/Groups/a99962b9f99d4c4fac67'))['members'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function memberRemovals(): iterable
    {
        yield 'default style with "$ref": null' => [['op' => 'Remove', 'path' => 'members', 'value' => [['$ref' => null, 'value' => self::MEMBER]]]];
        yield 'default style without "$ref"' => [['op' => 'Remove', 'path' => 'members', 'value' => [['value' => self::MEMBER]]]];
        yield 'aadOptscim062020 style, value filter' => [['op' => 'remove', 'path' => 'members[value eq "'.self::MEMBER.'"]']];
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('memberRemovals')]
    #[TestDox('Member remove, $_dataName, removes only that member and answers 204')]
    public function removesMember(array $operation): void
    {
        $this->seed(groups: [self::storedGroup('a99962b9f99d4c4fac67', 'displayName', ['members' => [['value' => self::MEMBER], ['value' => 'u2']]])]);

        self::assertNoContent($this->request('PATCH', '/Groups/a99962b9f99d4c4fac67', self::patchOp($operation)));
        $again = $this->request('PATCH', '/Groups/a99962b9f99d4c4fac67', self::patchOp($operation));

        self::assertNoContent($again);
        self::assertSame('W/"2"', $again->headers->get('ETag'));
        self::assertSame([['value' => 'u2']], self::json($this->request('GET', '/Groups/a99962b9f99d4c4fac67'))['members'] ?? null);
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
    #[TestDox('Group rename, $_dataName: GET without members, then PATCH answers 204')]
    public function renamesGroup(array $operation): void
    {
        $this->seed(groups: [self::storedGroup('fa2ce26709934589afc5', 'displayName', ['externalId' => '60f1bb27-2e1e-402d-bcc4-ec999564a194', 'members' => [['value' => self::MEMBER]]])]);

        $group = self::json($this->request('GET', '/Groups/fa2ce26709934589afc5?excludedAttributes=members'));
        self::assertSame(['schemas', 'id', 'meta', 'displayName', 'externalId'], array_keys($group));

        self::assertNoContent($this->request('PATCH', '/Groups/fa2ce26709934589afc5', self::patchOp($operation)));
        self::assertSame('1879db59-3bdf-4490-ad68-ab880a269474updatedDisplayName', ($this->groups->get('fa2ce26709934589afc5') ?? [])['displayName'] ?? null);
        self::assertSame([['value' => self::MEMBER]], ($this->groups->get('fa2ce26709934589afc5') ?? [])['members'] ?? null);
    }

    #[Test]
    #[TestDox('The membership check finds the group, with only its id, only when the user is a member')]
    public function answersMembershipCheck(): void
    {
        $this->seed(groups: [self::storedGroup('54D382A4-2050-4C03-94D1-E769F1D15682', 'displayName', ['members' => [['value' => '2819c223-7f76-453a-919d-413861904646']]])]);

        $member = $this->request('GET', '/Groups?filter=id eq "54D382A4-2050-4C03-94D1-E769F1D15682" and members eq "2819c223-7f76-453a-919d-413861904646"&attributes=id');

        self::assertSame([['schemas' => [self::GROUP], 'id' => '54D382A4-2050-4C03-94D1-E769F1D15682']], self::resources($member));
        self::assertEmptyListResponse($this->request('GET', '/Groups?filter=id eq "54D382A4-2050-4C03-94D1-E769F1D15682" and members eq "08e1d05d-121c-4561-8b96-473d93df9210"&attributes=id'));
    }

    #[Test]
    #[TestDox('Unquoted filter values of the old diagrams answer 400 invalidFilter, as RFC 7644 Figure 1 requires')]
    public function rejectsUnquotedFilterValues(): void
    {
        $this->seed();

        $response = $this->request('GET', '/Groups?filter=id eq 54D382A4-2050-4C03-94D1-E769F1D15682 and members eq 2819c223-7f76-453a-919d-413861904646&attributes=id');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalidFilter', self::json($response)['scimType'] ?? null);
    }

    #[Test]
    #[TestDox('Group delete answers 204; the displayName query then finds nothing and the members stay')]
    public function deletesGroup(): void
    {
        $this->seed([self::storedUser(self::MEMBER, 'bjensen')], [self::storedGroup('cdb1ce18f65944079d37', 'displayName', ['members' => [['value' => self::MEMBER]]])]);

        self::assertNoContent($this->request('DELETE', '/Groups/cdb1ce18f65944079d37'));

        self::assertEmptyListResponse($this->request('GET', '/Groups?excludedAttributes=members&filter=displayName eq "displayName"'));
        self::assertNotNull($this->users->get(self::MEMBER));
    }

    #[Test]
    #[TestDox('Roles: JSON-encoded role values are stored as strings, roles[primary eq "True"] paths create the primary role')]
    public function updatesRoles(): void
    {
        $this->seed([$this->entraUser('6764549bef60420686bc'), $this->entraUser('u2', ['userName' => 'jsmith'])]);
        $role = '{"id":"06b07648-ecfe-589f-9d2f-6325724a46ee","value":"Admin","displayName":"Admin"}';

        self::assertNoContent($this->request('PATCH', '/Users/6764549bef60420686bc', self::patchOp(['op' => 'Add', 'path' => 'roles', 'value' => [['value' => $role]]])));
        self::assertNoContent($this->request('PATCH', '/Users/u2', self::patchOp(
            ['op' => 'Add', 'path' => 'roles[primary eq "True"].display', 'value' => 'Admin'],
            ['op' => 'Add', 'path' => 'roles[primary eq "True"].type', 'value' => 'WindowsAzureActiveDirectoryRole'],
            ['op' => 'replace', 'path' => 'roles[primary eq true].value', 'value' => 'Admin'],
        )));

        self::assertSame([['value' => $role]], self::json($this->request('GET', '/Users/6764549bef60420686bc'))['roles'] ?? null);
        self::assertSame([['primary' => true, 'display' => 'Admin', 'type' => 'WindowsAzureActiveDirectoryRole', 'value' => 'Admin']], self::json($this->request('GET', '/Users/u2'))['roles'] ?? null);
        self::assertSame(['u2'], self::ids($this->request('GET', '/Users?filter=roles[primary eq "True"].value eq "Admin"')));
    }

    #[Test]
    #[TestDox('Roles: AssertiveAppRoleAssignmentsComplex replaces all roles')]
    public function replacesRoles(): void
    {
        $this->seed([$this->entraUser('6764549bef60420686bc', ['roles' => [['value' => 'Admin']]])]);
        $roles = [
            ['primary' => false, 'type' => 'WindowsAzureActiveDirectoryRole', 'display' => 'User', 'value' => 'User'],
            ['primary' => false, 'type' => 'WindowsAzureActiveDirectoryRole', 'display' => 'Test', 'value' => 'Test'],
        ];

        self::assertNoContent($this->request('PATCH', '/Users/6764549bef60420686bc', self::patchOp(['op' => 'replace', 'path' => 'roles', 'value' => $roles])));

        self::assertSame($roles, self::json($this->request('GET', '/Users/6764549bef60420686bc'))['roles'] ?? null);
    }

    #[Test]
    #[TestDox('A phoneNumbers primary that is not a boolean answers 400 invalidValue')]
    public function rejectsNonBooleanPrimary(): void
    {
        $this->seed();

        $response = $this->request('POST', '/Users', ['schemas' => [self::USER], 'userName' => self::USER_NAME, 'phoneNumbers' => [['value' => '(882)058-8632 x34575', 'type' => 'work', 'primary' => '(882)058-8632 x34575']]]);

        self::assertScimError($response, 400, 'invalidValue', 'Attribute "phoneNumbers.primary" must be a boolean.');
    }

    #[Test]
    #[TestDox('/Schemas lists the User, EnterpriseUser and Group schemas without null values and with camel case keywords')]
    public function discoversSchemas(): void
    {
        $this->seed();

        $response = $this->request('GET', '/Schemas');
        $schemas = self::resources($response);

        self::assertSame('application/scim+json', $response->headers->get('Content-Type'));
        self::assertSame([self::USER, self::ENTERPRISE, self::GROUP, 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig', 'urn:ietf:params:scim:schemas:core:2.0:ResourceType', 'urn:ietf:params:scim:schemas:core:2.0:Schema'], array_column($schemas, 'id'));
        self::assertStringNotContainsString('null', (string) $response->getContent());
        self::assertStringContainsString('"mutability":"readWrite"', (string) $response->getContent());
        self::assertSame(200, $this->request('GET', '/ResourceTypes')->getStatusCode());
        self::assertSame(200, $this->request('GET', '/ServiceProviderConfig')->getStatusCode());
    }

    #[Test]
    #[TestDox('SCIM_User_Pagination_Test: pages of 5 echo startIndex, report the total and cover every user once')]
    public function paginatesUsers(): void
    {
        $this->seed(array_map(static fn (int $i): array => self::storedUser('u'.$i, 'user'.$i), range(1, 12)));
        $startIndexes = [];
        $ids = [];
        $startIndex = 1;

        do {
            $page = self::json($this->request('GET', '/Users?startIndex='.$startIndex.'&count=5'));
            $startIndexes[] = $page['startIndex'] ?? null;
            $ids = [...$ids, ...array_column(\is_array($page['Resources'] ?? null) ? $page['Resources'] : [], 'id')];
            $startIndex = (int) ($page['startIndex'] ?? 0) + \count(\is_array($page['Resources'] ?? null) ? $page['Resources'] : []);
        } while ($startIndex <= ($page['totalResults'] ?? 0));

        self::assertSame([1, 6, 11], $startIndexes);
        self::assertSame(array_map(static fn (int $i): string => 'u'.$i, range(1, 12)), $ids);
        self::assertSame(['u1'], self::ids($this->request('GET', '/Users?startIndex=1&count=1')));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function entraUser(string $id, array $extra = []): array
    {
        return self::storedUser($id, self::USER_NAME, [
            'externalId' => '0a21f0f2-8d2a-4f8e-bf98-7363c4aed4ef',
            'name' => ['formatted' => 'givenName familyName', 'familyName' => 'familyName', 'givenName' => 'givenName'],
            'active' => true,
            'emails' => [['value' => self::EMAIL, 'type' => 'work', 'primary' => true]],
            ...$extra,
        ]);
    }
}
