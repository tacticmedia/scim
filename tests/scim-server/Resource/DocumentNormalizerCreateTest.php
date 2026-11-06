<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Resource;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Resource\DocumentNormalizer;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServer\Tests\Resource\Fixtures\ScimExceptionAssertions;
use TacticMedia\ScimServer\Tests\Resource\Fixtures\TestSchemas;

final class DocumentNormalizerCreateTest extends KernelTestCase
{
    use ScimExceptionAssertions;
    private const string USER = TestSchemas::USER;
    private const string ENTERPRISE = TestSchemas::ENTERPRISE;
    private const string CUSTOM = TestSchemas::CUSTOM;

    #[Test]
    #[TestDox('Rewrites names in any case to schema spelling and computes "schemas"')]
    public function rewritesNamesToSchemaSpelling(): void
    {
        self::assertSame([
            'schemas' => [self::USER],
            'externalId' => 'b1',
            'userName' => 'bjensen',
            'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen'],
        ], self::create([
            'Schemas' => ['urn:example:unknown:2.0:User'],
            'NAME' => ['GivenName' => 'Barbara', 'familyname' => 'Jensen'],
            'USERNAME' => 'bjensen',
            'ExternalId' => 'b1',
        ]));
    }

    #[Test]
    #[TestDox('Accepts extension attributes nested, URN-qualified or unqualified, with the URN missing from "schemas"')]
    public function acceptsExtensionAttributesInEveryForm(): void
    {
        self::assertSame([
            'schemas' => [self::USER, self::ENTERPRISE, self::CUSTOM],
            'userName' => 'bjensen',
            self::ENTERPRISE => ['employeeNumber' => '701984', 'costCenter' => '4130', 'manager' => ['value' => 'm1']],
            self::CUSTOM => ['tag' => 't1'],
        ], self::create([
            'schemas' => [self::USER],
            self::CUSTOM => ['TAG' => 't1'],
            'userName' => 'bjensen',
            strtoupper(self::ENTERPRISE) => ['COSTCENTER' => '4130'],
            self::ENTERPRISE.':EmployeeNumber' => '701984',
            'manager' => ['value' => 'm1'],
        ]));
    }

    #[Test]
    #[TestDox('Ignores unknown attributes, unknown schema URNs and ambiguous unqualified names')]
    public function ignoresUnknownAttributes(): void
    {
        self::assertSame([
            'schemas' => [self::USER],
            'userName' => 'bjensen',
            'name' => ['givenName' => 'Barbara'],
        ], self::create([
            'userName' => 'bjensen',
            'nickName2' => 'x',
            'urn:example:unknown:2.0:User' => ['tag' => 'x'],
            'urn:example:unknown:2.0:User:tag' => 'x',
            self::USER => ['displayName' => 'x'],
            'name.familyName' => 'x',
            'department' => 'x',
            '%%' => 'x',
            'name' => ['middle' => 'x', 'givenName' => 'Barbara'],
            self::ENTERPRISE => ['unknown' => 'x', 7 => 'x'],
        ]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function duplicateAttributes(): iterable
    {
        yield 'core attribute' => [['userName' => 'a', 'USERNAME' => 'b'], 'userName'];
        yield 'null and a value' => [['userName' => 'a', 'displayName' => null, 'DisplayName' => 'x'], 'displayName'];
        yield 'readOnly attribute' => [['userName' => 'a', 'id' => '1', 'ID' => '2'], 'id'];
        yield 'sub-attribute' => [['userName' => 'a', 'name' => ['givenName' => 'a', 'GIVENNAME' => 'b']], 'name.givenName'];
        yield 'nested and URN-qualified extension attribute' => [
            ['userName' => 'a', self::ENTERPRISE => ['costCenter' => '1'], self::ENTERPRISE.':costCenter' => '2'],
            self::ENTERPRISE.':costCenter',
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('duplicateAttributes')]
    #[TestDox('Rejects an attribute given twice in different case with invalidSyntax')]
    public function rejectsAnAttributeGivenTwice(array $input, string $path): void
    {
        self::assertScimException(
            ScimErrorType::InvalidSyntax,
            \sprintf('Attribute "%s" appears more than once.', $path),
            static fn (): array => self::create($input),
        );
    }

    #[Test]
    #[TestDox('Drops readOnly attributes and sub-attributes')]
    public function dropsReadOnlyAttributes(): void
    {
        self::assertSame([
            'schemas' => [self::USER, self::ENTERPRISE],
            'userName' => 'bjensen',
            'emails' => [['value' => 'b@example.com']],
            self::ENTERPRISE => ['costCenter' => '4130', 'manager' => ['value' => 'm1']],
        ], self::create([
            'id' => 'client-id',
            'meta' => ['resourceType' => 'User'],
            'groups' => [['value' => 'g1']],
            'userName' => 'bjensen',
            'emails' => [['value' => 'b@example.com', 'display' => 'Babs']],
            self::ENTERPRISE => ['costCenter' => '4130', 'manager' => ['value' => 'm1', 'displayName' => 'Boss']],
        ]));
    }

    #[Test]
    #[TestDox('Drops null, [] and {}, and keeps "" of an optional simple attribute')]
    public function dropsUnassignedValues(): void
    {
        self::assertSame([
            'schemas' => [self::USER],
            'userName' => 'bjensen',
            'nickName' => '',
        ], self::create([
            'userName' => 'bjensen',
            'displayName' => null,
            'nickName' => '',
            'emails' => [],
            'roles' => [null],
            'name' => ['givenName' => null],
            'badge' => [],
            self::ENTERPRISE => [],
            self::CUSTOM => null,
        ]));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function booleans(): iterable
    {
        yield 'string True' => ['True', true];
        yield 'string false' => ['false', false];
        yield 'string FALSE' => ['FALSE', false];
        yield 'JSON true' => [true, true];
    }

    #[Test]
    #[DataProvider('booleans')]
    #[TestDox('Coerces "true" and "false" in any case to a boolean')]
    public function coercesBooleanStrings(mixed $value, bool $expected): void
    {
        self::assertSame(
            ['schemas' => [self::USER], 'userName' => 'bjensen', 'active' => $expected, 'emails' => [['value' => 'b@example.com', 'primary' => $expected]]],
            self::create(['userName' => 'bjensen', 'active' => $value, 'emails' => [['value' => 'b@example.com', 'primary' => $value]]]),
        );
    }

    /**
     * @return iterable<string, array{mixed, array<string, string>|null}>
     */
    public static function managers(): iterable
    {
        yield 'bare id' => ['m1', ['value' => 'm1']];
        yield 'object' => [['value' => 'm1', '$REF' => '../Users/m1'], ['value' => 'm1', '$ref' => '../Users/m1']];
        yield 'list with one object' => [[['value' => 'm1']], ['value' => 'm1']];
        yield 'empty string' => ['', null];
    }

    /**
     * @param array<string, string>|null $expected
     */
    #[Test]
    #[DataProvider('managers')]
    #[TestDox('Accepts manager as an object, a bare id or a list with one object, and "" as unassigned')]
    public function acceptsManagerInEveryForm(mixed $manager, ?array $expected): void
    {
        self::assertSame(
            ['schemas' => [self::USER, self::ENTERPRISE], 'userName' => 'bjensen', self::ENTERPRISE => ['costCenter' => '4130', ...(null === $expected ? [] : ['manager' => $expected])]],
            self::create(['userName' => 'bjensen', self::ENTERPRISE => ['costCenter' => '4130', 'manager' => $manager]]),
        );
    }

    #[Test]
    #[TestDox('Wraps a single object where a list is expected')]
    public function wrapsASingleObject(): void
    {
        self::assertSame(
            ['schemas' => [self::USER], 'userName' => 'bjensen', 'emails' => [['value' => 'b@example.com', 'type' => 'work']]],
            self::create(['userName' => 'bjensen', 'emails' => ['value' => 'b@example.com', 'type' => 'work']]),
        );
    }

    #[Test]
    #[TestDox('Keeps the first primary value and sets the others to false')]
    public function keepsTheFirstPrimaryValue(): void
    {
        self::assertSame(
            ['schemas' => [self::USER], 'userName' => 'bjensen', 'emails' => [
                ['value' => 'a@example.com', 'primary' => false],
                ['value' => 'b@example.com', 'primary' => true],
                ['value' => 'c@example.com'],
                ['value' => 'd@example.com', 'primary' => false],
            ]],
            self::create(['userName' => 'bjensen', 'emails' => [
                ['value' => 'a@example.com', 'primary' => false],
                ['value' => 'b@example.com', 'primary' => 'true'],
                ['value' => 'c@example.com'],
                ['value' => 'd@example.com', 'primary' => true],
            ]]),
        );
    }

    #[Test]
    #[TestDox('Never alters the case of a value')]
    public function neverAltersTheCaseOfAValue(): void
    {
        $input = ['userName' => 'BJensen', 'nickName' => 'Babs', 'emails' => [['value' => 'BJensen@Example.COM', 'type' => 'Work']]];

        self::assertSame(['schemas' => [self::USER], ...$input], self::create($input));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'boolean' => [['active' => 'yes'], 'Attribute "active" must be a boolean.'];
        yield 'dateTime' => [['lastLogin' => '2008-01-23T04:56:22'], 'Attribute "lastLogin" must be an xsd:dateTime with a timezone.'];
        yield 'sub-attribute' => [['emails' => [['value' => 5]]], 'Attribute "emails.value" must be a string.'];
        yield 'extension sub-attribute' => [
            [self::ENTERPRISE => ['costCenter' => '1', 'manager' => ['value' => 5]]],
            'Attribute "'.self::ENTERPRISE.':manager.value" must be a string.',
        ];
        yield 'scalar for a multi-valued attribute' => [['roles' => 'admin'], 'Attribute "roles" must be a list.'];
        yield 'scalar for a multi-valued complex attribute' => [['emails' => 'b@example.com'], 'Attribute "emails" must be a list.'];
        yield 'list for a single-valued attribute' => [['displayName' => ['Babs']], 'Attribute "displayName" must be a string.'];
        yield 'list for a list element' => [['emails' => [['b@example.com']]], 'Attribute "emails" must be a complex object.'];
        yield 'string for a complex attribute without "value"' => [['name' => 'Barbara'], 'Attribute "name" must be a complex object.'];
        yield 'list of two for a single-valued complex attribute' => [
            [self::ENTERPRISE => ['costCenter' => '1', 'manager' => [['value' => 'a'], ['value' => 'b']]]],
            'Attribute "'.self::ENTERPRISE.':manager" must be a complex object.',
        ];
        yield 'extension that is not an object' => [[self::ENTERPRISE => 'x'], 'Attribute "'.self::ENTERPRISE.'" must be a complex object.'];
        yield 'extension that is a list of objects' => [[self::ENTERPRISE => [['costCenter' => '1']]], 'Attribute "'.self::ENTERPRISE.'" must be a complex object.'];
        yield 'extension that is a list of strings' => [[self::ENTERPRISE => ['x']], 'Attribute "'.self::ENTERPRISE.'" must be a complex object.'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('invalidValues')]
    #[TestDox('Rejects a value of the wrong type or shape with invalidValue and the attribute path')]
    public function rejectsAnInvalidValue(array $input, string $detail): void
    {
        self::assertScimException(
            ScimErrorType::InvalidValue,
            $detail,
            static fn (): array => self::create(['userName' => 'bjensen', ...$input]),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, string}>
     */
    public static function missingRequiredAttributes(): iterable
    {
        yield 'core attribute' => [['displayName' => 'Babs'], false, 'userName'];
        yield 'empty core attribute' => [['userName' => ''], false, 'userName'];
        yield 'attribute of an extension with values' => [['userName' => 'a', self::ENTERPRISE => ['department' => 'x']], false, self::ENTERPRISE.':costCenter'];
        yield 'attribute of a required extension' => [['userName' => 'a'], true, self::CUSTOM.':tag'];
        yield 'sub-attribute of a list element' => [['userName' => 'a', 'emails' => [['value' => 'x'], ['type' => 'work']]], false, 'emails.value'];
        yield 'empty sub-attribute' => [['userName' => 'a', 'emails' => [['value' => '']]], false, 'emails.value'];
        yield 'sub-attribute of an extension attribute' => [
            ['userName' => 'a', self::ENTERPRISE => ['costCenter' => '1', 'manager' => ['$ref' => '../Users/m1']]],
            false,
            self::ENTERPRISE.':manager.value',
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('missingRequiredAttributes')]
    #[TestDox('Rejects a missing or empty required attribute with invalidValue')]
    public function rejectsAMissingRequiredAttribute(array $input, bool $customRequired, string $path): void
    {
        self::assertScimException(
            ScimErrorType::InvalidValue,
            \sprintf('Attribute "%s" is required.', $path),
            static fn (): array => new DocumentNormalizer()->forCreate($input, TestSchemas::userType($customRequired)),
        );
    }

    #[Test]
    #[TestDox('Lists a required extension without values in "schemas", in resource type order')]
    public function listsARequiredExtensionWithoutValues(): void
    {
        $type = new ResourceType('User', '/Users', CoreSchemas::user(), [
            new SchemaExtension(CoreSchemas::enterpriseUser(), true),
            new SchemaExtension(new Schema(self::CUSTOM, 'Custom', '', [new AttributeDefinition('tag')])),
        ]);

        self::assertSame(
            ['schemas' => [self::USER, self::ENTERPRISE, self::CUSTOM], 'userName' => 'bjensen', self::CUSTOM => ['tag' => 't1']],
            new DocumentNormalizer()->forCreate(['userName' => 'bjensen', self::CUSTOM => ['tag' => 't1']], $type),
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private static function create(array $input): array
    {
        return new DocumentNormalizer()->forCreate($input, TestSchemas::userType());
    }
}
