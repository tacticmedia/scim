<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Resource\Fixtures;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServer\Schema\Uniqueness;

/**
 * A User-like resource type with two extensions that both define "department".
 */
final class TestSchemas
{
    public const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    public const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    public const string CUSTOM = 'urn:example:params:scim:schemas:extension:custom:2.0:User';

    public static function userType(bool $customRequired = false): ResourceType
    {
        return new ResourceType('User', '/Users', self::user(), [
            new SchemaExtension(self::enterprise()),
            new SchemaExtension(self::custom(), $customRequired),
        ]);
    }

    private static function user(): Schema
    {
        return new Schema(self::USER, 'User', 'User Account', [
            new AttributeDefinition('userName', required: true, uniqueness: Uniqueness::Server),
            new AttributeDefinition('name', AttributeType::Complex, subAttributes: [
                new AttributeDefinition('formatted'),
                new AttributeDefinition('familyName'),
                new AttributeDefinition('givenName'),
            ]),
            new AttributeDefinition('displayName'),
            new AttributeDefinition('nickName', caseExact: true),
            new AttributeDefinition('active', AttributeType::Boolean),
            new AttributeDefinition('password', mutability: Mutability::WriteOnly, returned: Returned::Never),
            new AttributeDefinition('emails', AttributeType::Complex, multiValued: true, subAttributes: [
                new AttributeDefinition('value', required: true),
                new AttributeDefinition('type'),
                new AttributeDefinition('primary', AttributeType::Boolean),
                new AttributeDefinition('display', mutability: Mutability::ReadOnly),
            ]),
            new AttributeDefinition('groups', AttributeType::Complex, multiValued: true, mutability: Mutability::ReadOnly, subAttributes: [
                new AttributeDefinition('value', mutability: Mutability::ReadOnly),
                new AttributeDefinition('$ref', AttributeType::Reference, mutability: Mutability::ReadOnly, referenceTypes: ['Group']),
                new AttributeDefinition('display', mutability: Mutability::ReadOnly),
            ]),
            new AttributeDefinition('roles', multiValued: true),
            new AttributeDefinition('lastLogin', AttributeType::DateTime),
            new AttributeDefinition('loginCount', AttributeType::Integer, returned: Returned::Request),
            new AttributeDefinition('score', AttributeType::Decimal),
            new AttributeDefinition('photo', AttributeType::Binary),
            new AttributeDefinition('profileUrl', AttributeType::Reference, referenceTypes: ['external']),
            new AttributeDefinition('badge', AttributeType::Complex, returned: Returned::Always, subAttributes: [
                new AttributeDefinition('code'),
                new AttributeDefinition('issuer', returned: Returned::Request),
                new AttributeDefinition('pin', returned: Returned::Never),
            ]),
        ]);
    }

    private static function enterprise(): Schema
    {
        return new Schema(self::ENTERPRISE, 'EnterpriseUser', 'Enterprise User', [
            new AttributeDefinition('employeeNumber', mutability: Mutability::Immutable),
            new AttributeDefinition('costCenter', required: true),
            new AttributeDefinition('department'),
            new AttributeDefinition('manager', AttributeType::Complex, subAttributes: [
                new AttributeDefinition('value', required: true, caseExact: true),
                new AttributeDefinition('$ref', AttributeType::Reference, referenceTypes: ['User']),
                new AttributeDefinition('displayName', required: true, mutability: Mutability::ReadOnly),
            ]),
        ]);
    }

    private static function custom(): Schema
    {
        return new Schema(self::CUSTOM, 'Custom', 'Custom User', [
            new AttributeDefinition('tag', required: true),
            new AttributeDefinition('department'),
            new AttributeDefinition('origin', AttributeType::Complex, mutability: Mutability::Immutable, subAttributes: [
                new AttributeDefinition('system'),
                new AttributeDefinition('key'),
            ]),
        ]);
    }
}
