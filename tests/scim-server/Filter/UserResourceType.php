<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Filter;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServer\SchemaUrn;

/**
 * A subset of the RFC 7643 User and Enterprise User schemas, plus an extension with one attribute of each other
 * type and attributes that are never returned. {@see ResourceType::resolve()} adds the common attributes id,
 * externalId and meta.
 */
final class UserResourceType
{
    public const string CORE = 'urn:ietf:params:scim:schemas:core:2.0:User:';
    public const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:';
    public const string TEST = 'urn:example:params:scim:schemas:extension:test:2.0:User:';

    public static function create(): ResourceType
    {
        return new ResourceType('User', '/Users', self::core(), [
            new SchemaExtension(self::enterprise()),
            new SchemaExtension(self::test()),
        ]);
    }

    private static function core(): Schema
    {
        return new Schema(SchemaUrn::User->value, 'User', 'User Account', [
            new AttributeDefinition('schemas', AttributeType::Reference, multiValued: true, caseExact: true),
            new AttributeDefinition('userName'),
            new AttributeDefinition('name', AttributeType::Complex, subAttributes: [
                new AttributeDefinition('formatted'),
                new AttributeDefinition('familyName'),
                new AttributeDefinition('givenName'),
                new AttributeDefinition('middleName'),
            ]),
            new AttributeDefinition('nickName'),
            new AttributeDefinition('password', mutability: Mutability::WriteOnly, returned: Returned::Never),
            new AttributeDefinition('title'),
            new AttributeDefinition('userType'),
            new AttributeDefinition('active', AttributeType::Boolean),
            self::multiValued('emails'),
            self::multiValued('ims'),
            new AttributeDefinition('photos', AttributeType::Complex, multiValued: true, subAttributes: [
                new AttributeDefinition('value', AttributeType::Reference, caseExact: true),
                new AttributeDefinition('type'),
            ]),
            new AttributeDefinition('addresses', AttributeType::Complex, multiValued: true, subAttributes: [
                new AttributeDefinition('streetAddress'),
                new AttributeDefinition('locality'),
                new AttributeDefinition('type'),
            ]),
            new AttributeDefinition('x509Certificates', AttributeType::Complex, multiValued: true, subAttributes: [
                new AttributeDefinition('value', AttributeType::Binary, caseExact: true),
            ]),
        ]);
    }

    private static function multiValued(string $name): AttributeDefinition
    {
        return new AttributeDefinition($name, AttributeType::Complex, multiValued: true, subAttributes: [
            new AttributeDefinition('value'),
            new AttributeDefinition('display'),
            new AttributeDefinition('type'),
            new AttributeDefinition('primary', AttributeType::Boolean),
        ]);
    }

    private static function enterprise(): Schema
    {
        return new Schema(SchemaUrn::EnterpriseUser->value, 'EnterpriseUser', 'Enterprise User', [
            new AttributeDefinition('employeeNumber'),
            new AttributeDefinition('manager', AttributeType::Complex, subAttributes: [
                new AttributeDefinition('value'),
                new AttributeDefinition('$ref', AttributeType::Reference),
                new AttributeDefinition('displayName'),
            ]),
        ]);
    }

    private static function test(): Schema
    {
        return new Schema(rtrim(self::TEST, ':'), 'Test', 'One attribute of each other type', [
            new AttributeDefinition('loginCount', AttributeType::Integer),
            new AttributeDefinition('score', AttributeType::Decimal),
            new AttributeDefinition('avatar', AttributeType::Binary),
            new AttributeDefinition('homepage', AttributeType::Reference),
            new AttributeDefinition('tags', multiValued: true),
            new AttributeDefinition('birthday', AttributeType::DateTime),
            new AttributeDefinition('pin', mutability: Mutability::WriteOnly),
            new AttributeDefinition('recoveryCodes', AttributeType::Complex, multiValued: true, returned: Returned::Never, subAttributes: [
                new AttributeDefinition('value'),
                new AttributeDefinition('type'),
            ]),
        ]);
    }
}
