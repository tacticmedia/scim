<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Schema\SchemaBuilder;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Device;

final class SchemaBuilderTest extends KernelTestCase
{
    #[Test]
    #[TestDox('Without mappings the core schema is empty and no extension is published')]
    public function nothingMappedPublishesAnEmptyCoreSchema(): void
    {
        $published = new SchemaBuilder()->publish(CoreResourceTypes::user(), []);

        self::assertSame([[]], array_map($this->names(...), $published->schemas()));
        self::assertSame(['User', '/Users', 'User Account'], [$published->name, $published->endpoint, $published->description]);
    }

    #[Test]
    #[TestDox('User publishes only the mapped attributes, in schema order')]
    public function userPublishesMappedAttributes(): void
    {
        $schemas = new AttributeLoader()->load(User::class)->resourceType->schemas();

        self::assertSame([
            ['userName', 'name', 'displayName', 'nickName', 'title', 'userType', 'active', 'password', 'emails', 'phoneNumbers', 'addresses', 'groups'],
            ['employeeNumber', 'department', 'manager'],
        ], array_map($this->names(...), $schemas));
        self::assertSame(['formatted', 'familyName', 'givenName'], $this->names($schemas[0]->attribute('name')));
        self::assertSame(['value', '$ref', 'displayName'], $this->names($schemas[1]->attribute('manager')));
    }

    #[Test]
    #[TestDox('A slot attribute publishes type with the mapped types, and primary')]
    public function slotAttributesPublishTypeAndPrimary(): void
    {
        $user = new AttributeLoader()->load(User::class)->resourceType->schema;

        self::assertSame(['value', 'type', 'primary'], $this->names($user->attribute('phoneNumbers')));
        self::assertSame(['work', 'mobile'], $user->attribute('phoneNumbers')?->subAttribute('type')?->canonicalValues);
        self::assertSame(['work'], $user->attribute('emails')?->subAttribute('type')?->canonicalValues);
    }

    #[Test]
    #[TestDox('A reference collection publishes type with its literals, or no type without a value filter')]
    public function referenceCollectionsPublishTheirTypes(): void
    {
        $group = new AttributeLoader()->load(Group::class)->resourceType->schema;
        $user = new AttributeLoader()->load(User::class)->resourceType->schema;

        self::assertSame(['displayName', 'members'], $this->names($group));
        self::assertSame(['User', 'Group'], $group->attribute('members')?->subAttribute('type')?->canonicalValues);
        self::assertSame(['value', '$ref', 'display'], $this->names($user->attribute('groups')));
    }

    #[Test]
    #[TestDox('Application schemas publish the characteristics of their mappings')]
    public function applicationSchemasPublishCharacteristics(): void
    {
        $device = new AttributeLoader()->load(Device::class)->resourceType;
        $core = $device->schema->toArray();
        $extension = $device->schemaExtensions[0];

        self::assertSame(['urn:acme:scim:schemas:core:1.0:Device', 'Device', 'A device'], [$core['id'], $core['name'], $core['description']]);
        self::assertSame(['status', 'enabled', 'weight', 'location', 'serialNumber', 'purchasedAt', 'label'], $this->names($device->schema));
        self::assertSame(
            ['name' => 'serialNumber', 'type' => 'string', 'multiValued' => false, 'description' => 'Serial number', 'required' => true, 'caseExact' => true, 'mutability' => 'readWrite', 'returned' => 'default', 'uniqueness' => 'server'],
            $device->schema->attribute('serialNumber')?->toArray(),
        );
        self::assertSame(['building', 'floor'], $this->names($device->schema->attribute('location')));
        self::assertSame(['DeviceAudit', 'Audit data', true], [$extension->schema->name, $extension->schema->description, $extension->required]);
        self::assertSame(
            ['name' => 'checkedBy', 'type' => 'reference', 'multiValued' => false, 'description' => '', 'required' => false, 'caseExact' => true, 'mutability' => 'readWrite', 'returned' => 'request', 'uniqueness' => 'global', 'canonicalValues' => ['robot'], 'referenceTypes' => []],
            $extension->schema->attribute('checkedBy')?->toArray(),
        );
    }

    #[Test]
    #[TestDox('Application attributes take their type from the PHP type')]
    public function applicationTypesComeFromPhpTypes(): void
    {
        $device = new AttributeLoader()->load(Device::class)->resourceType;
        $types = array_map(static fn (AttributeDefinition $definition): string => $definition->type->value, $device->schema->attributes);

        self::assertSame(['string', 'boolean', 'decimal', 'complex', 'string', 'dateTime', 'string'], $types);
        self::assertSame(['active', 'retired'], $device->schema->attribute('status')?->canonicalValues);
        self::assertSame('integer', $device->schema->attribute('location')?->subAttribute('floor')?->type->value);
        self::assertSame('string', $device->schemaExtensions[0]->schema->attribute('tag')?->type->value);
    }

    /**
     * @return list<string>
     */
    private function names(Schema|AttributeDefinition|null $node): array
    {
        $children = $node instanceof Schema ? $node->attributes : $node->subAttributes ?? [];

        return array_map(static fn (AttributeDefinition $definition): string => $definition->name, $children);
    }
}
