<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Patch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Tests\Filter\AssertsScimException;

final class PatchApplierGroupTest extends KernelTestCase
{
    use AssertsScimException;
    private const string ENTRA_MEMBER = 'f648f8d5ea4e4cd38e9c';
    private const string OKTA_MEMBER = '23a35c27-23d3-4c03-b4c5-6443c09e7173';
    private const string OKTA_OTHER_MEMBER = '89bb1940-b905-4575-9e7f-6f887cfb368e';
    private const string RFC_MEMBER = '2819c223-7f76-453a-919d-413861904646';
    private const string RFC_ADD = <<<'JSON'
        {
            "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
            "Operations": [{
                "op": "add",
                "path": "members",
                "value": [{
                    "display": "Babs Jensen",
                    "$ref": "https://example.com/v2/Users/2819c223-7f76-453a-919d-413861904646",
                    "value": "2819c223-7f76-453a-919d-413861904646"
                }]
            }]
        }
        JSON;
    private const string ENTRA_ADD = <<<'JSON'
        {
            "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
            "Operations": [{
                "op": "Add",
                "path": "members",
                "value": [{
                    "$ref": null,
                    "value": "f648f8d5ea4e4cd38e9c"
                }]
            }]
        }
        JSON;
    private const string OKTA_REMOVE_AND_ADD = <<<'JSON'
        {
            "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
            "Operations": [{
                "op": "remove",
                "path": "members[value eq \"89bb1940-b905-4575-9e7f-6f887cfb368e\"]"
                },
                {
                "op": "add",
                "path": "members",
                "value": [{
                    "value": "23a35c27-23d3-4c03-b4c5-6443c09e7173",
                    "display": "test.user@okta.local"
                }]
            }]
        }
        JSON;

    #[Test]
    #[TestDox('Adds an Entra member with "$ref": null')]
    public function addsEntraMember(): void
    {
        self::assertSame([['value' => 'u1'], ['value' => self::ENTRA_MEMBER]], self::patch(PatchFixtures::group(['u1']), self::ENTRA_ADD)['members']);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function repeatedAdds(): iterable
    {
        yield 'Entra' => [[self::ENTRA_MEMBER], self::ENTRA_ADD];
        yield 'Okta resend with display' => [[self::OKTA_MEMBER], PatchFixtures::body('{"op": "add", "path": "members", "value": [{"value": "'.self::OKTA_MEMBER.'", "display": "test.user@okta.local"}]}')];
        yield 'same member twice in one value' => [[self::OKTA_MEMBER], PatchFixtures::body('{"op": "add", "path": "members", "value": [{"value": "'.self::OKTA_MEMBER.'"}, {"value": "'.self::OKTA_MEMBER.'"}]}')];
        yield 'RFC 7644 section 3.5.2.1 example' => [[self::RFC_MEMBER], self::RFC_ADD];
        yield 'member with a type' => [[self::RFC_MEMBER], PatchFixtures::body('{"op": "add", "path": "members", "value": [{"value": "'.self::RFC_MEMBER.'", "type": "User"}]}')];
    }

    /**
     * @param list<string> $memberIds
     */
    #[Test]
    #[DataProvider('repeatedAdds')]
    #[TestDox('Adds a present member without change')]
    public function addsPresentMemberWithoutChange(array $memberIds, string $body): void
    {
        $group = PatchFixtures::group($memberIds);

        self::assertSame($group, self::patch($group, $body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function memberRemovals(): iterable
    {
        yield 'Entra default style' => [<<<'JSON'
            {
                "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
                "Operations": [{
                    "op": "Remove",
                    "path": "members",
                    "value": [{
                        "$ref": null,
                        "value": "f648f8d5ea4e4cd38e9c"
                    }]
                }]
            }
            JSON];
        yield 'Entra default style without $ref' => [PatchFixtures::body('{"op": "Remove", "path": "members", "value": [{"value": "f648f8d5ea4e4cd38e9c"}]}')];
        yield 'Entra flag style' => [PatchFixtures::body('{"op": "remove", "path": "members[value eq \"f648f8d5ea4e4cd38e9c\"]"}')];
        yield 'Keycloak list' => [PatchFixtures::body('{"op":"remove","path":"members","value":[{"value":"f648f8d5ea4e4cd38e9c"}]}')];
        yield 'Keycloak fallback' => [PatchFixtures::body('{"op":"remove","path":"members[value eq \"f648f8d5ea4e4cd38e9c\"]"}')];
        yield 'RFC example without space' => [PatchFixtures::body('{"op":"remove","path":"members[value eq\"f648f8d5ea4e4cd38e9c\"]"}')];
        yield 'value list with $ref and type' => [PatchFixtures::body('{"op":"remove","path":"members","value":[{"value":"f648f8d5ea4e4cd38e9c","$ref":"https://example.com/v2/Users/f648f8d5ea4e4cd38e9c","type":"User"}]}')];
    }

    #[Test]
    #[DataProvider('memberRemovals')]
    #[TestDox('Removes only the named member')]
    public function removesOnlyNamedMember(string $body): void
    {
        self::assertSame([['value' => 'u1'], ['value' => 'u2']], self::patch(PatchFixtures::group(['u1', self::ENTRA_MEMBER, 'u2']), $body)['members']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function removalsOfAbsentMembers(): iterable
    {
        yield 'value list' => [PatchFixtures::body('{"op": "Remove", "path": "members", "value": [{"value": "absent"}]}')];
        yield 'empty value list' => [PatchFixtures::body('{"op": "Remove", "path": "members", "value": []}')];
        yield 'value filter' => [PatchFixtures::body('{"op": "remove", "path": "members[value eq \"absent\"]"}')];
        yield 'sub-attribute of a filter' => [PatchFixtures::body('{"op": "remove", "path": "members[value eq \"absent\"].type"}')];
    }

    #[Test]
    #[DataProvider('removalsOfAbsentMembers')]
    #[TestDox('Removes an absent member without change (RFC 7644 section 3.5.2.2)')]
    public function removesAbsentMemberWithoutChange(string $body): void
    {
        $group = PatchFixtures::group(['u1']);

        self::assertSame($group, self::patch($group, $body));
    }

    #[Test]
    #[TestDox('Removes all members for "remove" with path "members" and no value')]
    public function removesAllMembers(): void
    {
        self::assertArrayNotHasKey('members', self::patch(PatchFixtures::group(['u1', 'u2']), PatchFixtures::body('{"op":"remove","path":"members"}')));
    }

    #[Test]
    #[TestDox('Applies the Okta remove and add in order and drops the readOnly display')]
    public function appliesOktaRemoveAndAdd(): void
    {
        self::assertSame(
            [['value' => 'u1'], ['value' => self::OKTA_MEMBER]],
            self::patch(PatchFixtures::group(['u1', self::OKTA_OTHER_MEMBER]), self::OKTA_REMOVE_AND_ADD)['members'],
        );
    }

    /**
     * @return iterable<string, array{string, list<array<string, string>>|null}>
     */
    public static function membershipPushes(): iterable
    {
        yield 'Okta full push' => [
            <<<'JSON'
                {
                  "schemas": [
                    "urn:ietf:params:scim:api:messages:2.0:PatchOp"
                  ],
                  "Operations": [
                    {
                      "op": "replace",
                      "path": "members",
                      "value": [
                        {
                          "value": "23a35c27-23d3-4c03-b4c5-6443c09e7173",
                          "display": "test.user@okta.local"
                        },
                        {
                          "value": "89bb1940-b905-4575-9e7f-6f887cfb368e",
                          "display": "test.user@okta.local"
                        }
                      ]
                    }
                  ]
                }
                JSON,
            [['value' => self::OKTA_MEMBER], ['value' => self::OKTA_OTHER_MEMBER]],
        ];
        yield 'empty group push' => [PatchFixtures::body('{"op": "replace", "path": "members", "value": []}'), null];
    }

    /**
     * @param list<array<string, string>>|null $expected
     */
    #[Test]
    #[DataProvider('membershipPushes')]
    #[TestDox('Replaces the whole member list')]
    public function replacesMemberList(string $body, ?array $expected): void
    {
        self::assertSame($expected, self::patch(PatchFixtures::group(['u1', self::OKTA_MEMBER]), $body)['members'] ?? null);
    }

    #[Test]
    #[TestDox('Adds members in a Keycloak chunk')]
    public function addsKeycloakChunk(): void
    {
        self::assertSame(
            [['value' => 'u1'], ['value' => 'u2'], ['value' => 'u3']],
            self::patch(PatchFixtures::group(['u1']), PatchFixtures::body('{"op":"add","path":"members","value":[{"value":"u2"},{"value":"u1"},{"value":"u3"}]}'))['members'],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function renames(): iterable
    {
        yield 'Entra' => [PatchFixtures::body('{"op": "Replace", "path": "displayName", "value": "1879db59-3bdf-4490-ad68-ab880a269474updatedDisplayName"}')];
        yield 'Okta with the readOnly id' => [<<<'JSON'
            {
                "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
                "Operations": [{
                    "op": "replace",
                    "value": {
                        "id": "abf4dd94-a4c0-4f67-89c9-76b03340cb9b",
                        "displayName": "1879db59-3bdf-4490-ad68-ab880a269474updatedDisplayName"
                    }
                }]
            }
            JSON];
        yield 'Keycloak' => [PatchFixtures::body('{"op":"replace","path":"displayName","value":"1879db59-3bdf-4490-ad68-ab880a269474updatedDisplayName"}')];
    }

    #[Test]
    #[DataProvider('renames')]
    #[TestDox('Renames a group')]
    public function renamesGroup(string $body): void
    {
        $group = PatchFixtures::group(['u1']);
        $group['displayName'] = '1879db59-3bdf-4490-ad68-ab880a269474updatedDisplayName';

        self::assertSame($group, self::patch(PatchFixtures::group(['u1']), $body));
    }

    #[Test]
    #[TestDox('Returns the stored document for an Okta rename to the same name')]
    public function keepsDocumentForUnchangedRename(): void
    {
        $group = PatchFixtures::group(['u1']);
        $body = PatchFixtures::body('{"op": "replace", "value": {"id": "abf4dd94-a4c0-4f67-89c9-76b03340cb9b", "displayName": "Test SCIMv2"}}');

        self::assertSame($group, self::patch($group, $body));
    }

    #[Test]
    #[TestDox('Rejects a path-less id that differs from the stored id')]
    public function rejectsChangedId(): void
    {
        self::assertThrowsScimException(
            ScimErrorType::Mutability,
            'Operations[0]: Attribute "'.PatchFixtures::GROUP.':id" is readOnly.',
            static fn (): array => self::patch(PatchFixtures::group(), PatchFixtures::body('{"op": "replace", "value": {"id": "e9e30dba-f08f-4109-8486-d5c6a331660a", "displayName": "Tour Guides"}}')),
        );
    }

    /**
     * @return iterable<string, array{string, ScimErrorType, string}>
     */
    public static function memberMutabilityViolations(): iterable
    {
        yield 'immutable members.value' => [
            '{"op": "replace", "path": "members[value eq \"u1\"].value", "value": "u2"}',
            ScimErrorType::Mutability,
            'Attribute "'.PatchFixtures::GROUP.':members.value" is immutable.',
        ];
        yield 'readOnly members.display' => [
            '{"op": "add", "path": "members[value eq \"u1\"].display", "value": "Babs"}',
            ScimErrorType::Mutability,
            'Attribute "'.PatchFixtures::GROUP.':members.display" is readOnly.',
        ];
        yield 'readOnly members.display on a new member' => [
            '{"op": "add", "path": "members[value eq \"u2\"].display", "value": "Babs"}',
            ScimErrorType::Mutability,
            'Attribute "'.PatchFixtures::GROUP.':members.display" is readOnly.',
        ];
        yield 'required displayName' => ['{"op": "remove", "path": "displayName"}', ScimErrorType::Mutability, 'Attribute "'.PatchFixtures::GROUP.':displayName" is required.'];
        yield 'required displayName replaced with ""' => ['{"op": "replace", "path": "displayName", "value": ""}', ScimErrorType::Mutability, 'Attribute "'.PatchFixtures::GROUP.':displayName" is required.'];
        yield 'readOnly meta' => ['{"op": "remove", "path": "meta"}', ScimErrorType::Mutability, 'Attribute "'.PatchFixtures::GROUP.':meta" is readOnly.'];
        yield 'readOnly meta sub-attribute' => ['{"op": "replace", "path": "meta.version", "value": "W/\"1\""}', ScimErrorType::Mutability, 'Attribute "'.PatchFixtures::GROUP.':meta.version" is readOnly.'];
    }

    #[Test]
    #[DataProvider('memberMutabilityViolations')]
    #[TestDox('Rejects a change that the mutability of the attribute forbids')]
    public function rejectsMutabilityViolation(string $operation, ScimErrorType $scimType, string $detail): void
    {
        self::assertThrowsScimException($scimType, 'Operations[0]: '.$detail, static fn (): array => self::patch(PatchFixtures::group(['u1']), PatchFixtures::body($operation)));
    }

    #[Test]
    #[TestDox('Matches member ids case-sensitively, as ids are caseExact')]
    public function matchesMemberIdsCaseSensitively(): void
    {
        $group = PatchFixtures::group(['u1']);

        self::assertSame($group, self::patch($group, PatchFixtures::body('{"op": "remove", "path": "members[value eq \"U1\"]"}')));
        self::assertSame([['value' => 'u1'], ['value' => 'U1']], self::patch($group, PatchFixtures::body('{"op": "add", "path": "members", "value": [{"value": "U1"}]}'))['members']);
    }

    #[Test]
    #[TestDox('Sets an immutable sub-attribute that has no value')]
    public function setsImmutableSubAttributeWithoutValue(): void
    {
        self::assertSame(
            [['value' => 'u1', 'type' => 'User']],
            self::patch(PatchFixtures::group(['u1']), PatchFixtures::body('{"op": "add", "path": "members[value eq \"u1\"].type", "value": "User"}'))['members'],
        );
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function patch(array $document, string $body): array
    {
        return PatchFixtures::patch($document, $body, CoreResourceTypes::group());
    }
}
