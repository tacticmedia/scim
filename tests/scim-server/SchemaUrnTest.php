<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\SchemaUrn;

final class SchemaUrnTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{SchemaUrn, string}>
     */
    public static function rfc7643SchemaRegistry(): iterable
    {
        yield 'User Resource' => [SchemaUrn::User, 'urn:ietf:params:scim:schemas:core:2.0:User'];
        yield 'Enterprise User Extension' => [SchemaUrn::EnterpriseUser, 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User'];
        yield 'Group Resource' => [SchemaUrn::Group, 'urn:ietf:params:scim:schemas:core:2.0:Group'];
        yield 'Service Provider Configuration Schema' => [SchemaUrn::ServiceProviderConfig, 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'];
        yield 'Resource Type Configuration' => [SchemaUrn::ResourceType, 'urn:ietf:params:scim:schemas:core:2.0:ResourceType'];
        yield 'Schema Definitions Schema' => [SchemaUrn::Schema, 'urn:ietf:params:scim:schemas:core:2.0:Schema'];
    }

    #[DataProvider('rfc7643SchemaRegistry')]
    #[TestDox('The case value equals the RFC 7643 Section 10.4 schema URI')]
    public function testTheCaseValueEqualsTheRegisteredSchemaUri(SchemaUrn $schemaUrn, string $registeredSchemaUri): void
    {
        self::assertSame($registeredSchemaUri, $schemaUrn->value);
    }
}
