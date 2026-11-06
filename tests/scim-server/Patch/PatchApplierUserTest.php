<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Patch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;

final class PatchApplierUserTest extends KernelTestCase
{
    private const string ENTERPRISE = PatchFixtures::ENTERPRISE;
    private const string WORK_EMAIL = PatchFixtures::WORK_EMAIL;
    private const string MANAGER = PatchFixtures::MANAGER;

    #[Test]
    #[TestDox('Applies the Entra default style update of several attributes, one operation each')]
    public function appliesEntraOneOperationPerAttribute(): void
    {
        $user = $this->patch(PatchFixtures::user(), <<<'JSON'
            {
                "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
                "Operations": [
                    {"op": "Replace", "path": "displayName", "value": "Pvlo"},
                    {"op": "Replace", "path": "emails[type eq \"work\"].value", "value": "TestBcwqnm@test.microsoft.com"},
                    {"op": "Replace", "path": "name.givenName", "value": "Gtfd"},
                    {"op": "Replace", "path": "name.familyName", "value": "Pkqf"},
                    {"op": "Replace", "path": "externalId", "value": "Eqpj"},
                    {"op": "Replace", "path": "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:employeeNumber", "value": "Eqpj"}
                ]
            }
            JSON);

        self::assertSame(
            ['Pvlo', 'TestBcwqnm@test.microsoft.com', 'Gtfd', 'Pkqf', 'Eqpj', 'Eqpj'],
            [$user['displayName'], $user['emails'][0]['value'], $user['name']['givenName'], $user['name']['familyName'], $user['externalId'], $user[self::ENTERPRISE]['employeeNumber']],
        );
    }

    #[Test]
    #[TestDox('Applies the Entra flag style update: path-less value with dotted and URN-qualified keys')]
    public function appliesEntraFlagStyleUpdate(): void
    {
        $user = $this->patch(PatchFixtures::user(), <<<'JSON'
            {
                "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
                "Operations": [
                    {
                        "op": "replace",
                        "path": "emails[type eq \"work\"].value",
                        "value": "TestMhvaes@test.microsoft.com"
                    },
                    {
                        "op": "replace",
                        "value": {
                            "displayName": "Bjfe",
                            "name.givenName": "Kkom",
                            "name.familyName": "Unua",
                            "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:employeeNumber": "Aklq"
                        }
                    }
                ]
            }
            JSON);

        self::assertSame(['value' => 'TestMhvaes@test.microsoft.com', 'type' => 'work', 'primary' => true], $user['emails'][0]);
        self::assertSame('Bjfe', $user['displayName']);
        self::assertSame(['formatted' => 'givenName familyName', 'familyName' => 'Unua', 'givenName' => 'Kkom'], $user['name']);
        self::assertSame(['employeeNumber' => 'Aklq', 'manager' => ['value' => self::MANAGER]], $user[self::ENTERPRISE]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function workEmailCreations(): iterable
    {
        yield 'Entra default style' => [PatchFixtures::body('{"op": "Add", "path": "emails[type eq \"work\"].value", "value": "babs@example.com"}')];
    }

    #[Test]
    #[DataProvider('workEmailCreations')]
    #[TestDox('Creates the work email from the filter when the user has none')]
    public function createsWorkEmailFromFilter(string $body): void
    {
        $user = PatchFixtures::user();
        unset($user['emails']);

        self::assertSame([['type' => 'work', 'value' => 'babs@example.com']], $this->patch($user, $body)['emails']);
    }

    #[Test]
    #[TestDox('Adds a work email next to a home email when the filter matches nothing')]
    public function addsWorkEmailNextToHomeEmail(): void
    {
        $user = PatchFixtures::user();
        $user['emails'] = [['value' => 'babs@jensen.org', 'type' => 'home']];

        self::assertSame(
            [['value' => 'babs@jensen.org', 'type' => 'home'], ['type' => 'work', 'value' => 'babs@example.com']],
            $this->patch($user, PatchFixtures::body('{"op": "Add", "path": "emails[type eq \"work\"].value", "value": "babs@example.com"}'))['emails'],
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function activeChanges(): iterable
    {
        yield 'Entra default style "False"' => [PatchFixtures::body('{"op": "Replace", "path": "active", "value": "False"}'), false];
        yield 'Entra flag style false' => [PatchFixtures::body('{"op": "replace", "path": "active", "value": false}'), false];
        yield 'Okta deactivation without path' => [PatchFixtures::body('{"op": "replace", "value": {"active": false}}'), false];
    }

    #[Test]
    #[DataProvider('activeChanges')]
    #[TestDox('Disables and restores a user')]
    public function changesActive(string $body, bool $expected): void
    {
        $user = PatchFixtures::user();
        $user['active'] = !$expected;

        self::assertSame($expected, $this->patch($user, $body)['active']);
    }

    /**
     * @return iterable<string, array{string, array<string, string>|null}>
     */
    public static function managerChanges(): iterable
    {
        $ref = 'http://example.com/scim/Users/00aa00aa-bb11-cc22-dd33-44ee44ee44ee';
        $id = '00aa00aa-bb11-cc22-dd33-44ee44ee44ee';

        yield 'list with one object, path without URN' => [
            PatchFixtures::body('{"op": "Add", "path": "manager", "value": [{"$ref": "'.$ref.'", "value": "'.$id.'"}]}'),
            ['value' => $id, '$ref' => $ref],
        ];
        yield 'bare id' => [
            PatchFixtures::body('{"op": "Add", "path": "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:manager", "value": "45619541-95de-4d5e-9872-571b5d2c5577"}'),
            ['value' => '45619541-95de-4d5e-9872-571b5d2c5577'],
        ];
        yield 'object' => [
            PatchFixtures::body('{"op": "Add", "path": "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:manager", "value": {"value": "'.$id.'"}}'),
            ['value' => $id],
        ];
        yield 'replace with ""' => [PatchFixtures::body('{"op": "replace", "path": "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:manager", "value": ""}'), null];
        yield 'remove manager.value' => [PatchFixtures::body('{"op": "remove", "path": "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:manager.value"}'), null];
    }

    /**
     * @param array<string, string>|null $expected
     */
    #[Test]
    #[DataProvider('managerChanges')]
    #[TestDox('Sets and clears the manager in the forms that Entra sends')]
    public function changesManager(string $body, ?array $expected): void
    {
        self::assertSame($expected, $this->patch(PatchFixtures::user(), $body)[self::ENTERPRISE]['manager'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function nullUpdates(): iterable
    {
        yield 'replace with null' => [PatchFixtures::body('{"op": "replace", "path": "nickName", "value": null}'), 'Tour Guide'];
        yield 'path-less replace with ""' => [PatchFixtures::body('{"op": "replace", "value": {"nickName": "", "title": ""}}'), null];
    }

    #[Test]
    #[DataProvider('nullUpdates')]
    #[TestDox('Clears optional attributes as the Entra null update test does')]
    public function clearsOptionalAttributes(string $body, ?string $expectedTitle): void
    {
        $user = PatchFixtures::user();
        $user['nickName'] = 'Babs';
        $user['title'] = 'Tour Guide';

        $patched = $this->patch($user, $body);

        self::assertSame([null, $expectedTitle], [$patched['nickName'] ?? null, $patched['title'] ?? null]);
    }

    #[Test]
    #[TestDox('Syncs an Okta password with and without path')]
    public function syncsPassword(): void
    {
        $user = $this->patch(PatchFixtures::user(), '{"schemas":["urn:ietf:params:scim:api:messages:2.0:PatchOp"],"Operations":[{"op":"replace","value":{"password":"t1meMa$heen"}}]}');
        self::assertSame('t1meMa$heen', $user['password']);

        $user = $this->patch($user, PatchFixtures::body('{"op":"replace","path":"password","value":"n3wPa$$"}'));
        self::assertSame('n3wPa$$', $user['password']);
    }

    #[Test]
    #[TestDox('Merges the object value of a path that is the extension URN (Keycloak)')]
    public function mergesExtensionUrnPath(): void
    {
        $user = $this->patch(PatchFixtures::user(), PatchFixtures::body(<<<'JSON'
            {"op": "replace", "path": "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User", "value": {"department": "Tour Operations", "manager": {"value": "m2"}, "unknown": "x"}}
            JSON));

        self::assertSame(['employeeNumber' => '701984', 'manager' => ['value' => 'm2'], 'department' => 'Tour Operations'], $user[self::ENTERPRISE]);
    }

    #[Test]
    #[TestDox('Merges a path-less extension object and its dotted keys')]
    public function mergesPathlessExtensionObject(): void
    {
        $user = $this->patch(PatchFixtures::user(), PatchFixtures::body(<<<'JSON'
            {"op": "add", "value": {"urn:ietf:params:scim:schemas:extension:enterprise:2.0:User": {"costCenter": "4130", "manager.value": "m2"}}}
            JSON));

        self::assertSame(['employeeNumber' => '701984', 'manager' => ['value' => 'm2'], 'costCenter' => '4130'], $user[self::ENTERPRISE]);
    }

    #[Test]
    #[TestDox('Applies the Keycloak user update: name object, emails list and simple attributes')]
    public function appliesKeycloakUserUpdate(): void
    {
        $user = $this->patch(PatchFixtures::user(), <<<'JSON'
            {"schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"], "Operations": [
                {"op": "replace", "path": "userName", "value": "bjensen"},
                {"op": "replace", "path": "name", "value": {"givenName": "Barbara", "familyName": "Jensen"}},
                {"op": "replace", "path": "emails", "value": [{"value": "bjensen@example.com", "type": "work", "primary": true}]},
                {"op": "replace", "path": "locale", "value": "en-US"}
            ]}
            JSON);

        self::assertSame(
            ['bjensen', ['formatted' => 'givenName familyName', 'familyName' => 'Jensen', 'givenName' => 'Barbara'], [['value' => 'bjensen@example.com', 'type' => 'work', 'primary' => true]], 'en-US'],
            [$user['userName'], $user['name'], $user['emails'], $user['locale']],
        );
    }

    #[Test]
    #[TestDox('Adds emails and replaces nickName without path (RFC 7644 section 3.5.2.1 example)')]
    public function appliesRfcPathlessAdd(): void
    {
        $user = $this->patch(PatchFixtures::user(), PatchFixtures::body('{"op":"add","value":{"emails":[{"value":"babs@jensen.org","type":"home"}],"nickname":"Babs"}}'));

        self::assertSame([['value' => self::WORK_EMAIL, 'type' => 'work', 'primary' => true], ['value' => 'babs@jensen.org', 'type' => 'home']], $user['emails']);
        self::assertSame('Babs', $user['nickName']);
    }

    /**
     * @return iterable<string, array{string, list<array<string, mixed>>}>
     */
    public static function roleChanges(): iterable
    {
        yield 'SingleAppRoleAssignment with "True"' => [
            PatchFixtures::body('{"op": "Replace", "path": "roles[primary eq \"True\"].value", "value": "Admin"}'),
            [['primary' => true, 'value' => 'Admin']],
        ];
        yield 'AppRoleAssignmentsComplex with JSON strings' => [
            PatchFixtures::body(<<<'JSON'
                {
                    "op": "Add",
                    "path": "roles",
                    "value": [
                        {"value": "{\"id\":\"06b07648-ecfe-589f-9d2f-6325724a46ee\",\"value\":\"Admin\",\"displayName\":\"Admin\"}"},
                        {"value": "{\"id\":\"06b07648-ecfe-599f-9d2f-6325724a46ee\",\"value\":\"User\",\"displayName\":\"User\"}"}
                    ]
                }
                JSON),
            [
                ['value' => '{"id":"06b07648-ecfe-589f-9d2f-6325724a46ee","value":"Admin","displayName":"Admin"}'],
                ['value' => '{"id":"06b07648-ecfe-599f-9d2f-6325724a46ee","value":"User","displayName":"User"}'],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $expected
     */
    #[Test]
    #[DataProvider('roleChanges')]
    #[TestDox('Sets roles in the forms that Entra sends')]
    public function changesRoles(string $body, array $expected): void
    {
        self::assertSame($expected, $this->patch(PatchFixtures::user(), $body)['roles']);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function patch(array $document, string $body): array
    {
        return PatchFixtures::patch($document, $body, CoreResourceTypes::user());
    }
}
