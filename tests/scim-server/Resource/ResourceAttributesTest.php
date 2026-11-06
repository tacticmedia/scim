<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Resource\ResourceAttributes;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServer\Tests\Resource\Fixtures\TestSchemas;

final class ResourceAttributesTest extends KernelTestCase
{
    private const string USER = TestSchemas::USER;
    private const string ENTERPRISE = TestSchemas::ENTERPRISE;

    #[Test]
    #[TestDox('all() lists common, core and extension attributes; a common attribute replaces a core one')]
    public function allListsCommonCoreAndExtensionAttributes(): void
    {
        $core = new Schema(self::USER, 'User', '', [new AttributeDefinition('id', returned: Returned::Default), new AttributeDefinition('userName')]);
        $enterprise = new Schema(self::ENTERPRISE, 'EnterpriseUser', '', [new AttributeDefinition('costCenter')]);
        $type = new ResourceType('User', '/Users', $core, [new SchemaExtension($enterprise)]);

        self::assertSame(
            [[self::USER, 'id', Returned::Always], [self::USER, 'externalId', Returned::Default], [self::USER, 'meta', Returned::Default], [self::USER, 'userName', Returned::Default], [self::ENTERPRISE, 'costCenter', Returned::Default]],
            array_map(static fn (ResolvedAttribute $attribute): array => [$attribute->schema->id, $attribute->attribute->name, $attribute->attribute->returned], ResourceAttributes::all($type)),
        );
    }

    #[Test]
    #[TestDox('label() is unqualified for a core attribute and URN-qualified for an extension attribute')]
    public function labelQualifiesOnlyExtensionAttributes(): void
    {
        $type = TestSchemas::userType();
        $name = $type->resolve(new AttributePath(null, 'name', 'givenName'));
        $manager = $type->resolve(new AttributePath(null, 'manager', 'value'));
        self::assertNotNull($name);
        self::assertNotNull($manager);

        self::assertSame(['name.givenName', self::ENTERPRISE.':manager.value'], [ResourceAttributes::label($name, $type), ResourceAttributes::label($manager, $type)]);
    }
}
