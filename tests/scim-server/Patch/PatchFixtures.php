<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Patch;

use PHPUnit\Framework\Assert;
use TacticMedia\ScimServer\Patch\PatchApplier;
use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;

/**
 * Stored documents in schema spelling, as a repository returns them.
 */
final class PatchFixtures
{
    public const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    public const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    public const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    public const string DEVICE = 'urn:example:params:scim:schemas:core:2.0:Device';
    public const string USER_ID = '6764549bef60420686bc';
    public const string GROUP_ID = 'abf4dd94-a4c0-4f67-89c9-76b03340cb9b';
    public const string WORK_EMAIL = 'Test_User_11bb11bb-cc22-dd33-ee44-55ff55ff55ff@testuser.com';
    public const string MANAGER = '26118915-6090-4610-87e4-49d8ca9f808d';

    /**
     * @return array<string, mixed>
     */
    public static function user(): array
    {
        return [
            'schemas' => [self::USER, self::ENTERPRISE],
            'id' => self::USER_ID,
            'externalId' => '0a21f0f2-8d2a-4f8e-bf98-7363c4aed4ef',
            'meta' => ['resourceType' => 'User', 'created' => '2018-03-27T19:59:26.000Z', 'lastModified' => '2018-03-27T19:59:26.000Z'],
            'userName' => 'Test_User_00aa00aa-bb11-cc22-dd33-44ee44ee44ee',
            'name' => ['formatted' => 'givenName familyName', 'familyName' => 'familyName', 'givenName' => 'givenName'],
            'active' => true,
            'emails' => [['value' => self::WORK_EMAIL, 'type' => 'work', 'primary' => true]],
            self::ENTERPRISE => ['employeeNumber' => '701984', 'manager' => ['value' => self::MANAGER]],
        ];
    }

    /**
     * @param list<string> $memberIds
     *
     * @return array<string, mixed>
     */
    public static function group(array $memberIds = []): array
    {
        $group = [
            'schemas' => [self::GROUP],
            'id' => self::GROUP_ID,
            'meta' => ['resourceType' => 'Group', 'created' => '2018-03-27T19:59:26.000Z', 'lastModified' => '2018-03-27T19:59:26.000Z'],
            'displayName' => 'Test SCIMv2',
        ];

        if ([] !== $memberIds) {
            $group['members'] = array_map(static fn (string $id): array => ['value' => $id], $memberIds);
        }

        return $group;
    }

    /**
     * A resource type with attributes that the core schemas do not have: an immutable top-level attribute and a
     * multi-valued string.
     */
    public static function deviceType(): ResourceType
    {
        return new ResourceType('Device', '/Devices', new Schema(self::DEVICE, 'Device', 'Device', [
            new AttributeDefinition('serialNumber', mutability: Mutability::Immutable),
            new AttributeDefinition('tags', AttributeType::String, multiValued: true),
        ]));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public static function patch(array $document, string $body, ResourceType $type): array
    {
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded);

        return new PatchApplier()->apply($document, PatchRequest::fromArray($decoded), $type);
    }

    /**
     * One operation in a PatchOp message.
     */
    public static function body(string $operation): string
    {
        return '{"schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"], "Operations": ['.$operation.']}';
    }
}
