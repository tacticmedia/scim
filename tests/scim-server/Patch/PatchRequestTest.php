<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Patch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Patch\PatchOperation;
use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Tests\Filter\AssertsScimException;

final class PatchRequestTest extends KernelTestCase
{
    use AssertsScimException;
    private const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    /**
     * @return iterable<string, array{string, list<array{PatchOperationType, string|null, mixed}>}>
     */
    public static function wireBodies(): iterable
    {
        yield 'Entra default style' => [
            <<<'JSON'
                {
                    "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
                    "Operations": [
                            {
                            "op": "Replace",
                            "path": "emails[type eq \"work\"].value",
                            "value": "updatedEmail@microsoft.com"
                            },
                            {
                            "op": "Replace",
                            "path": "name.familyName",
                            "value": "updatedFamilyName"
                            }
                    ]
                }
                JSON,
            [
                [PatchOperationType::Replace, 'emails[type eq "work"].value', 'updatedEmail@microsoft.com'],
                [PatchOperationType::Replace, 'name.familyName', 'updatedFamilyName'],
            ],
        ];
        yield 'Entra tutorial, Operations before schemas' => [
            '{"Operations": [{"op": "Replace", "path": "active", "value": false}], "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"]}',
            [[PatchOperationType::Replace, 'active', false]],
        ];
        yield 'Entra flag style member removal without value' => [
            PatchFixtures::body('{"op": "remove", "path": "members[value eq \"7f4bc1a3-285e-48ae-8202-5accb43efb0e\"]"}'),
            [[PatchOperationType::Remove, 'members[value eq "7f4bc1a3-285e-48ae-8202-5accb43efb0e"]', null]],
        ];
        yield 'Okta deactivation without path' => [
            PatchFixtures::body('{"op": "replace", "value": {"active": false}}'),
            [[PatchOperationType::Replace, null, ['active' => false]]],
        ];
        yield 'Entra null update test' => [
            PatchFixtures::body('{"op": "replace", "path": "nickName", "value": null}'),
            [[PatchOperationType::Replace, 'nickName', null]],
        ];
        yield 'member names and schema URN in other case' => [
            '{"SCHEMAS": ["URN:IETF:PARAMS:SCIM:API:MESSAGES:2.0:PATCHOP"], "operations": [{"OP": "ADD", "Path": "nickName", "VALUE": "Babs"}]}',
            [[PatchOperationType::Add, 'nickName', 'Babs']],
        ];
    }

    /**
     * @param list<array{PatchOperationType, string|null, mixed}> $expected
     */
    #[Test]
    #[DataProvider('wireBodies')]
    #[TestDox('Reads the operations of a PatchOp message')]
    public function readsOperations(string $body, array $expected): void
    {
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        self::assertSame($expected, array_map(
            static fn (PatchOperation $operation): array => [$operation->type, $operation->path, $operation->value],
            PatchRequest::fromArray($decoded)->operations,
        ));
    }

    /**
     * @return iterable<string, array{array<mixed>, ScimErrorType, string}>
     */
    public static function malformedBodies(): iterable
    {
        $operation = ['op' => 'add', 'path' => 'nickName', 'value' => 'Babs'];
        $schemaDetail = '"schemas" must contain "'.self::PATCH_OP.'".';
        $operationsDetail = '"Operations" must be a non-empty list of operations.';

        yield 'no schemas' => [['Operations' => [$operation]], ScimErrorType::InvalidSyntax, $schemaDetail];
        yield 'resource schema' => [['schemas' => [PatchFixtures::USER], 'Operations' => [$operation]], ScimErrorType::InvalidSyntax, $schemaDetail];
        yield 'no Operations' => [['schemas' => [self::PATCH_OP]], ScimErrorType::InvalidSyntax, $operationsDetail];
        yield 'empty Operations' => [['schemas' => [self::PATCH_OP], 'Operations' => []], ScimErrorType::InvalidSyntax, $operationsDetail];
        yield 'one operation instead of a list' => [['schemas' => [self::PATCH_OP], 'Operations' => $operation], ScimErrorType::InvalidSyntax, $operationsDetail];
        yield 'operation is a string' => [self::message('add'), ScimErrorType::InvalidSyntax, 'Operations[0] must be an object.'];
        yield 'operation is a list' => [self::message(['add']), ScimErrorType::InvalidSyntax, 'Operations[0] must be an object.'];
        yield 'no op' => [self::message(['path' => 'nickName', 'value' => 'Babs']), ScimErrorType::InvalidSyntax, 'Operations[0]: "op" must be "add", "remove" or "replace".'];
        yield 'JSON Patch op' => [self::message(['op' => 'move', 'path' => 'nickName']), ScimErrorType::InvalidSyntax, 'Operations[0]: "op" must be "add", "remove" or "replace".'];
        yield 'path is not a string' => [self::message(['op' => 'add', 'path' => ['nickName'], 'value' => 'Babs']), ScimErrorType::InvalidSyntax, 'Operations[0]: "path" must be a string.'];
        yield 'remove without path' => [self::message(['op' => 'remove']), ScimErrorType::NoTarget, 'Operations[0]: "remove" needs a "path".'];
        yield 'add without value' => [self::message(['op' => 'add', 'path' => 'nickName']), ScimErrorType::InvalidValue, 'Operations[0]: "add" needs a "value".'];
        yield 'error in the second operation' => [
            ['schemas' => [self::PATCH_OP], 'Operations' => [$operation, ['op' => 'Replace']]],
            ScimErrorType::InvalidValue,
            'Operations[1]: "replace" needs a "value".',
        ];
    }

    /**
     * @param array<mixed> $body
     */
    #[Test]
    #[DataProvider('malformedBodies')]
    #[TestDox('Rejects a malformed PatchOp message')]
    public function rejectsMalformedMessage(array $body, ScimErrorType $scimType, string $detail): void
    {
        self::assertThrowsScimException($scimType, $detail, static fn (): PatchRequest => PatchRequest::fromArray($body));
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(mixed $operation): array
    {
        return ['schemas' => [self::PATCH_OP], 'Operations' => [$operation]];
    }
}
