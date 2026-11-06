<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Patch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Patch\PatchApplier;
use TacticMedia\ScimServer\Patch\PatchOperation;
use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServer\Tests\Filter\AssertsScimException;

final class PatchApplierRulesTest extends KernelTestCase
{
    use AssertsScimException;
    private const string USER = PatchFixtures::USER;
    private const string WORK = PatchFixtures::WORK_EMAIL;
    private const array HOME = ['value' => 'babs@jensen.org', 'type' => 'home'];

    /**
     * @return iterable<string, array{string, list<array<string, mixed>>}>
     */
    public static function primaryChanges(): iterable
    {
        yield 'add a primary value' => [
            '{"op": "add", "path": "emails", "value": [{"value": "babs@example.org", "type": "other", "primary": true}]}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => false], self::HOME, ['value' => 'babs@example.org', 'type' => 'other', 'primary' => true]],
        ];
        yield 'set primary through a value filter' => [
            '{"op": "replace", "path": "emails[type eq \"home\"].primary", "value": "True"}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => false], self::HOME + ['primary' => true]],
        ];
        yield 'replace the list' => [
            '{"op": "replace", "path": "emails", "value": [{"value": "a@example.org", "primary": true}, {"value": "b@example.org", "primary": true}]}',
            [['value' => 'a@example.org', 'primary' => true], ['value' => 'b@example.org', 'primary' => false]],
        ];
        yield 'set primary on the primary value' => [
            '{"op": "replace", "path": "emails[type eq \"work\"].primary", "value": true}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => true], self::HOME],
        ];
        yield 'set primary false' => [
            '{"op": "replace", "path": "emails[type eq \"work\"].primary", "value": false}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => false], self::HOME],
        ];
        yield 'add a stored value with primary' => [
            '{"op": "add", "path": "emails", "value": [{"value": "babs@jensen.org", "primary": true}]}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => false], self::HOME + ['primary' => true]],
        ];
        yield 'add the primary value with primary false' => [
            '{"op": "add", "path": "emails", "value": {"value": "'.self::WORK.'", "type": "work", "primary": false}}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => false], self::HOME],
        ];
    }

    /**
     * @param list<array<string, mixed>> $expected
     */
    #[Test]
    #[DataProvider('primaryChanges')]
    #[TestDox('Keeps one primary value: the one that the operation sets (RFC 7644 section 3.5.2)')]
    public function keepsOnePrimaryValue(string $operation, array $expected): void
    {
        self::assertSame($expected, self::patch($this->userWithHomeEmail(), PatchFixtures::body($operation))['emails']);
    }

    /**
     * @return iterable<string, array{string, list<array<string, mixed>>|null}>
     */
    public static function valueFilterEdits(): iterable
    {
        yield 'replace replaces the selected value' => [
            '{"op":"replace","path":"emails[type eq \"home\"]","value":{"type":"home","value":"babs@home.example.org"}}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => true], ['type' => 'home', 'value' => 'babs@home.example.org']],
        ];
        yield 'add merges into the selected value' => [
            '{"op":"add","path":"emails[type eq \"home\"]","value":{"display":"Home"}}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => true], self::HOME + ['display' => 'Home']],
        ];
        yield 'replace with null removes the selected value' => [
            '{"op":"replace","path":"emails[type eq \"home\"]","value":null}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => true]],
        ];
        yield 'replace creates the value from the filter' => [
            '{"op":"replace","path":"emails[type eq \"other\" and value eq \"o@example.org\"]","value":{"display":"Other"}}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => true], self::HOME, ['type' => 'other', 'value' => 'o@example.org', 'display' => 'Other']],
        ];
        yield 'remove with a logical filter' => [
            '{"op":"remove","path":"emails[type eq \"work\" and value ew \"testuser.com\"]"}',
            [self::HOME],
        ];
        yield 'remove a sub-attribute of the selected values' => [
            '{"op":"remove","path":"emails[type eq \"work\"].primary"}',
            [['value' => self::WORK, 'type' => 'work'], self::HOME],
        ];
        yield 'capitalized sub-attribute (Entra Q19)' => [
            '{"op":"Replace","path":"emails[type eq \"home\"].Value","value":"babs@home.example.org"}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => true], ['value' => 'babs@home.example.org', 'type' => 'home']],
        ];
        yield 'URN-qualified path' => [
            '{"op":"replace","path":"urn:ietf:params:scim:schemas:core:2.0:User:emails[type eq \"home\"].value","value":"babs@home.example.org"}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => true], ['value' => 'babs@home.example.org', 'type' => 'home']],
        ];
        yield 'sub-attribute without filter sets every value' => [
            '{"op":"replace","path":"emails.display","value":"Babs"}',
            [['value' => self::WORK, 'type' => 'work', 'primary' => true, 'display' => 'Babs'], self::HOME + ['display' => 'Babs']],
        ];
        yield 'removing the last sub-attribute removes the value' => [
            '{"op":"remove","path":"emails.value"}',
            [['type' => 'work', 'primary' => true], ['type' => 'home']],
        ];
        yield 'replace the list with ""' => ['{"op":"replace","path":"emails","value":""}', null];
    }

    /**
     * @param list<array<string, mixed>>|null $expected
     */
    #[Test]
    #[DataProvider('valueFilterEdits')]
    #[TestDox('Edits the values that a path selects')]
    public function editsSelectedValues(string $operation, ?array $expected): void
    {
        self::assertSame($expected, self::patch($this->userWithHomeEmail(), PatchFixtures::body($operation))['emails'] ?? null);
    }

    /**
     * @return iterable<string, array{string, array<string, string>|null}>
     */
    public static function nameEdits(): iterable
    {
        yield 'remove a sub-attribute' => ['{"op":"remove","path":"name.formatted"}', ['familyName' => 'familyName', 'givenName' => 'givenName']];
        yield 'remove an absent sub-attribute' => ['{"op":"remove","path":"name.middleName"}', ['formatted' => 'givenName familyName', 'familyName' => 'familyName', 'givenName' => 'givenName']];
        yield 'replace with "" (Entra Q12)' => ['{"op":"replace","path":"name.formatted","value":""}', ['familyName' => 'familyName', 'givenName' => 'givenName']];
        yield 'remove the attribute' => ['{"op":"remove","path":"name"}', null];
        yield 'add a sub-attribute' => ['{"op":"add","path":"name.middleName","value":"Jane"}', ['formatted' => 'givenName familyName', 'familyName' => 'familyName', 'givenName' => 'givenName', 'middleName' => 'Jane']];
    }

    /**
     * @param array<string, string>|null $expected
     */
    #[Test]
    #[DataProvider('nameEdits')]
    #[TestDox('Edits a single-valued complex attribute')]
    public function editsComplexAttribute(string $operation, ?array $expected): void
    {
        self::assertSame($expected, self::patch(PatchFixtures::user(), PatchFixtures::body($operation))['name'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operationsWithoutChange(): iterable
    {
        yield 'remove an absent attribute' => ['{"op":"remove","path":"nickName"}'];
        yield 'remove an absent readOnly attribute' => ['{"op":"remove","path":"groups"}'];
        yield 'replace with the stored value' => ['{"op":"replace","path":"userName","value":"Test_User_00aa00aa-bb11-cc22-dd33-44ee44ee44ee"}'];
        yield 'replace the readOnly id with the stored value' => ['{"op":"replace","path":"id","value":"6764549bef60420686bc"}'];
        yield 'add the stored email' => ['{"op":"add","path":"emails","value":{"value":"'.PatchFixtures::WORK_EMAIL.'"}}'];
        yield 'add the stored email with its primary' => ['{"op":"add","path":"emails","value":{"value":"'.PatchFixtures::WORK_EMAIL.'","primary":true}}'];
        yield 'replace with a filter that matches nothing and null' => ['{"op":"replace","path":"emails[type eq \"home\"].value","value":null}'];
        yield 'add an empty list' => ['{"op":"add","path":"emails","value":[]}'];
        yield 'path-less with unknown keys' => ['{"op":"add","value":{"schemas":["'.self::USER.'"],"foo":"bar","urn:example:unknown:User":{"a":"b"},"name[givenName eq \"x\"]":"y"}}'];
        yield 'path-less empty object' => ['{"op":"replace","value":{}}'];
    }

    #[Test]
    #[DataProvider('operationsWithoutChange')]
    #[TestDox('Returns the stored document when nothing changes (RFC 7644 section 3.5.2.1)')]
    public function returnsStoredDocumentWithoutChange(string $operation): void
    {
        self::assertSame(PatchFixtures::user(), self::patch(PatchFixtures::user(), PatchFixtures::body($operation)));
    }

    /**
     * @return iterable<string, array{string, ScimErrorType, string}>
     */
    public static function invalidOperations(): iterable
    {
        yield 'unknown attribute' => ['{"op":"add","path":"foo","value":"x"}', ScimErrorType::InvalidPath, "The attribute 'foo' is not defined for the resource type User."];
        yield 'unknown sub-attribute after a filter' => ['{"op":"add","path":"emails[type eq \"work\"].foo","value":"x"}', ScimErrorType::InvalidPath, "The attribute 'emails.foo' is not defined for the resource type User."];
        yield 'path that does not parse' => [
            '{"op":"add","path":"name.givenName.x","value":"x"}',
            ScimErrorType::InvalidPath,
            "'name.givenName.x' is not a valid path. Expected attrPath, attrPath[valFilter] or attrPath[valFilter].subAttr.",
        ];
        yield 'value filter on a single-valued attribute' => [
            '{"op":"add","path":"name[givenName eq \"x\"].familyName","value":"x"}',
            ScimErrorType::InvalidPath,
            "A value filter selects values of a multi-valued attribute, and '".self::USER.":name' is not one.",
        ];
        yield 'filter that does not parse' => ['{"op":"add","path":"emails[type eq]","value":"x"}', ScimErrorType::InvalidFilter, 'Expected a comparison value (a JSON string, number, true, false or null), found the end of the filter.'];
        yield 'filter on an unknown sub-attribute' => ['{"op":"add","path":"emails[foo eq \"x\"].value","value":"x"}', ScimErrorType::InvalidFilter, "The attribute 'emails.foo' is not defined for the resource type User."];
        yield 'filter that cannot create a value (RFC 7644 section 3.5.2.3)' => [
            '{"op":"replace","path":"emails[type eq \"other\" or type eq \"home\"].value","value":"x"}',
            ScimErrorType::NoTarget,
            "No value of '".self::USER.":emails' matches the path, and only a value filter of eq comparisons joined by 'and' can create one.",
        ];
        yield 'filter with eq null' => [
            '{"op":"add","path":"emails[type eq null].value","value":"x"}',
            ScimErrorType::NoTarget,
            "No value of '".self::USER.":emails' matches the path, and only a value filter of eq comparisons joined by 'and' can create one.",
        ];
        yield 'sub-attribute of a list without values' => [
            '{"op":"replace","path":"phoneNumbers.value","value":"x"}',
            ScimErrorType::NoTarget,
            "No value of '".self::USER.":phoneNumbers' matches the path, and only a value filter of eq comparisons joined by 'and' can create one.",
        ];
        yield 'path-less value that is not an object' => ['{"op":"replace","value":[{"active":false}]}', ScimErrorType::InvalidValue, 'The value must be an object of attributes.'];
        yield 'extension URN path with a string' => ['{"op":"replace","path":"'.PatchFixtures::ENTERPRISE.'","value":"x"}', ScimErrorType::InvalidValue, 'The value must be an object of attributes.'];
        yield 'value of the wrong type' => ['{"op":"replace","path":"active","value":"maybe"}', ScimErrorType::InvalidValue, 'Attribute "'.self::USER.':active" must be a boolean.'];
        yield 'two values for a value filter' => [
            '{"op":"replace","path":"emails[type eq \"work\"]","value":[{"value":"a"},{"value":"b"}]}',
            ScimErrorType::InvalidValue,
            'Attribute "'.self::USER.':emails" takes one value when a value filter selects it.',
        ];
        yield 'readOnly id' => ['{"op":"remove","path":"id"}', ScimErrorType::Mutability, 'Attribute "'.self::USER.':id" is readOnly.'];
        yield 'readOnly manager.displayName' => [
            '{"op":"add","path":"manager.displayName","value":"Boss"}',
            ScimErrorType::Mutability,
            'Attribute "'.PatchFixtures::ENTERPRISE.':manager.displayName" is readOnly.',
        ];
        yield 'required userName' => ['{"op":"remove","path":"userName"}', ScimErrorType::Mutability, 'Attribute "'.self::USER.':userName" is required.'];
        yield 'add to the readOnly groups' => ['{"op":"add","path":"groups","value":[{"value":"g1"}]}', ScimErrorType::Mutability, 'Attribute "'.self::USER.':groups" is readOnly.'];
        yield 'path-less replace of the readOnly groups' => ['{"op":"replace","value":{"groups":[{"value":"g1"}]}}', ScimErrorType::Mutability, 'Attribute "'.self::USER.':groups" is readOnly.'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function storedGroups(): iterable
    {
        yield 'path-less replace' => ['{"op":"replace","value":{"groups":[{"value":"g1","display":"Sales","type":"direct"}]}}'];
        yield 'add' => ['{"op":"add","path":"groups","value":[{"value":"g1"}]}'];
    }

    #[Test]
    #[DataProvider('storedGroups')]
    #[TestDox('Accepts the stored readOnly groups (Okta quirk 2)')]
    public function acceptsStoredGroups(string $operation): void
    {
        $user = PatchFixtures::user();
        $user['groups'] = [['value' => 'g1', 'display' => 'Sales', 'type' => 'direct']];

        self::assertSame($user, self::patch($user, PatchFixtures::body($operation)));
    }

    #[Test]
    #[DataProvider('invalidOperations')]
    #[TestDox('Rejects an operation with the RFC 7644 error type and its index')]
    public function rejectsInvalidOperation(string $operation, ScimErrorType $scimType, string $detail): void
    {
        self::assertThrowsScimException($scimType, 'Operations[0]: '.$detail, static fn (): array => self::patch(PatchFixtures::user(), PatchFixtures::body($operation)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function extensionPaths(): iterable
    {
        yield 'schema URN' => [PatchFixtures::ENTERPRISE];
        yield 'schema URN in another case' => [strtoupper(PatchFixtures::ENTERPRISE)];
    }

    #[Test]
    #[DataProvider('extensionPaths')]
    #[TestDox('remove with an extension schema URN as path removes every attribute of the extension')]
    public function removesExtension(string $path): void
    {
        $expected = PatchFixtures::user();
        unset($expected[PatchFixtures::ENTERPRISE]);

        self::assertSame($expected, self::patch(PatchFixtures::user(), PatchFixtures::body('{"op":"remove","path":"'.$path.'"}')));
    }

    #[Test]
    #[TestDox('remove with an extension schema URN rejects a stored readOnly attribute (RFC 7644 section 3.5.2.2)')]
    public function rejectsExtensionRemovalOfReadOnlyValue(): void
    {
        $audit = new Schema('urn:example:scim:schemas:extension:2.0:Audit', 'Audit', '', [new AttributeDefinition('reviewedBy', mutability: Mutability::ReadOnly)]);
        $type = new ResourceType('Device', '/Devices', PatchFixtures::deviceType()->schema, [new SchemaExtension($audit)]);

        self::assertThrowsScimException(
            ScimErrorType::Mutability,
            'Operations[0]: Attribute "'.$audit->id.':reviewedBy" is readOnly.',
            static fn (): array => PatchFixtures::patch([$audit->id => ['reviewedBy' => 'x']], PatchFixtures::body('{"op":"remove","path":"'.$audit->id.'"}'), $type),
        );
    }

    #[Test]
    #[TestDox('Rejects a "remove" without path that bypassed fromArray()')]
    public function rejectsRemoveWithoutPath(): void
    {
        self::assertThrowsScimException(
            ScimErrorType::NoTarget,
            'Operations[0]: A "remove" operation needs a "path".',
            static fn (): array => new PatchApplier()->apply(PatchFixtures::user(), new PatchRequest([new PatchOperation(PatchOperationType::Remove)]), CoreResourceTypes::user()),
        );
    }

    #[Test]
    #[TestDox('Fails the whole request when a later operation fails, and names its index (RFC 7644 section 3.5.2)')]
    public function failsWholeRequest(): void
    {
        self::assertThrowsScimException(
            ScimErrorType::Mutability,
            'Operations[1]: Attribute "'.self::USER.':id" is readOnly.',
            static fn (): array => self::patch(PatchFixtures::user(), '{"schemas":["urn:ietf:params:scim:api:messages:2.0:PatchOp"],"Operations":[{"op":"replace","path":"nickName","value":"Babs"},{"op":"replace","path":"id","value":"x"}]}'),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, array<string, mixed>}>
     */
    public static function deviceChanges(): iterable
    {
        yield 'add an immutable attribute without value' => [[], '{"op":"add","path":"serialNumber","value":"SN-1"}', ['serialNumber' => 'SN-1']];
        yield 'replace an immutable attribute without value (RFC 7644 section 3.5.2.3)' => [[], '{"op":"replace","path":"serialNumber","value":"SN-1"}', ['serialNumber' => 'SN-1']];
        yield 'replace an immutable attribute with its value' => [['serialNumber' => 'SN-1'], '{"op":"replace","path":"serialNumber","value":"SN-1"}', ['serialNumber' => 'SN-1']];
        yield 'add a string that equals a stored one' => [['tags' => ['Blue']], '{"op":"add","path":"tags","value":["blue","red"]}', ['tags' => ['Blue', 'red']]];
        yield 'remove listed strings' => [['tags' => ['blue', 'red', 'green']], '{"op":"remove","path":"tags","value":["RED","green"]}', ['tags' => ['blue']]];
        yield 'remove the last listed string' => [['tags' => ['blue']], '{"op":"remove","path":"tags","value":"blue"}', []];
        yield 'replace the strings with "" (Entra Q12)' => [['tags' => ['a', 'b']], '{"op":"replace","path":"tags","value":""}', []];
        yield 'add "" to the strings' => [['tags' => ['a', 'b']], '{"op":"add","path":"tags","value":""}', ['tags' => ['a', 'b']]];
        yield 'remove "" from the strings' => [['tags' => ['a', '']], '{"op":"remove","path":"tags","value":""}', ['tags' => ['a']]];
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('deviceChanges')]
    #[TestDox('Applies the immutable and multi-valued string rules to a custom resource type')]
    public function changesDevice(array $device, string $operation, array $expected): void
    {
        self::assertSame($expected, PatchFixtures::patch($device, PatchFixtures::body($operation), PatchFixtures::deviceType()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function immutableChanges(): iterable
    {
        yield 'add' => ['{"op":"add","path":"serialNumber","value":"SN-2"}'];
        yield 'replace' => ['{"op":"replace","path":"serialNumber","value":"SN-2"}'];
        yield 'remove' => ['{"op":"remove","path":"serialNumber"}'];
    }

    #[Test]
    #[DataProvider('immutableChanges')]
    #[TestDox('Rejects a change of a stored immutable value (RFC 7643 section 7)')]
    public function rejectsImmutableChange(string $operation): void
    {
        self::assertThrowsScimException(
            ScimErrorType::Mutability,
            'Operations[0]: Attribute "'.PatchFixtures::DEVICE.':serialNumber" is immutable.',
            static fn (): array => PatchFixtures::patch(['serialNumber' => 'SN-1'], PatchFixtures::body($operation), PatchFixtures::deviceType()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function userWithHomeEmail(): array
    {
        $user = PatchFixtures::user();
        $user['emails'][] = self::HOME;

        return $user;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function patch(array $document, string $body): array
    {
        return PatchFixtures::patch($document, $body, CoreResourceTypes::user());
    }
}
