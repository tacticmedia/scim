<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServerBundle\Converter\VersionConverter;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ConstructorParameter;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Enum\UserType;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\DerivedUser;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Device;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\DeviceFactory;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\TagConverter;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Team;

final class AttributeLoaderTest extends KernelTestCase
{
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    private const string DEVICE = 'urn:acme:scim:schemas:core:1.0:Device';
    private const string AUDIT = 'urn:acme:scim:schemas:extension:audit:1.0:Device';

    /**
     * @return iterable<string, array{class-string, string, MappingKind, string}>
     */
    public static function kinds(): iterable
    {
        yield 'simple attribute' => [User::class, 'userName', MappingKind::Field, self::USER.':userName'];
        yield 'sub-attribute' => [User::class, 'firstName', MappingKind::Field, self::USER.':name.givenName'];
        yield 'common attribute' => [User::class, 'externalId', MappingKind::Field, self::USER.':externalId'];
        yield 'meta sub-attribute' => [User::class, 'updatedAt', MappingKind::Field, self::USER.':meta.lastModified'];
        yield 'enterprise name without URN' => [User::class, 'department', MappingKind::Field, self::ENTERPRISE.':department'];
        yield 'slot' => [User::class, 'email', MappingKind::Slot, self::USER.':emails.value'];
        yield 'reference' => [User::class, 'manager', MappingKind::Reference, self::ENTERPRISE.':manager'];
        yield 'reference collection without filter' => [User::class, 'groups', MappingKind::ReferenceCollection, self::USER.':groups'];
        yield 'reference collection with filter' => [Group::class, 'users', MappingKind::ReferenceCollection, self::GROUP.':members'];
        yield 'implicit id' => [User::class, 'id', MappingKind::Field, self::USER.':id'];
        yield 'method' => [Device::class, 'label', MappingKind::Computed, self::DEVICE.':label'];
        yield 'application extension' => [Device::class, 'checkedBy', MappingKind::Field, self::AUDIT.':checkedBy'];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('kinds')]
    #[TestDox('$member of $class is a $kind of $path')]
    public function inferTheKindAndQualifyThePath(string $class, string $member, MappingKind $kind, string $path): void
    {
        $mapping = $this->member($this->load($class), $member);

        self::assertSame($kind, $mapping->kind);
        self::assertSame($path, (string) $mapping->path);
    }

    #[Test]
    #[TestDox('Slots carry their type, and every member of the primary group is primary')]
    public function slotsCarryTypeAndPrimary(): void
    {
        $user = $this->load(User::class);
        $slots = array_map(
            static fn (AttributeMapping $mapping): array => [$mapping->member, $mapping->type, $mapping->primary],
            $user->attributesAt(new AttributePath(self::USER, 'phoneNumbers', 'value')),
        );

        self::assertSame([['phone', 'work', true], ['mobile', 'mobile', false]], $slots);
        self::assertTrue($this->member($user, 'email')->primary);
    }

    #[Test]
    #[TestDox('attributesOf() gives every mapping of a top-level attribute')]
    public function attributesOfGivesSubAttributeMappings(): void
    {
        $members = array_map(static fn (AttributeMapping $mapping): string => $mapping->member, $this->load(User::class)->attributesOf(new AttributePath(self::USER, 'name')));

        self::assertSame(['firstName', 'lastName', 'getFormattedName'], $members);
    }

    #[Test]
    #[TestDox('References carry the target type and the adder and remover of a writable collection')]
    public function referencesCarryTargetAndCollectionAccess(): void
    {
        $user = $this->load(User::class);
        $group = $this->load(Group::class);

        self::assertSame(['User', true, null, null], $this->access($this->member($user, 'manager')));
        self::assertSame(['Group', false, null, null], $this->access($this->member($user, 'groups')));
        self::assertSame(['User', true, 'addUser', 'removeUser'], $this->access($this->member($group, 'users')));
        self::assertSame(['Group', true, 'addSubgroup', 'removeSubgroup'], $this->access($this->member($group, 'subgroups')));
    }

    #[Test]
    #[TestDox('The member type, nullability, default and required flag are compiled')]
    public function memberFactsAreCompiled(): void
    {
        $user = $this->load(User::class);

        self::assertSame([Uuid::class, false, false], [$user->id->phpType, $user->id->nullable, $user->id->writable]);
        self::assertContains($user->id, $user->attributes);
        self::assertSame(['bool', false, true, false], [$this->member($user, 'enabled')->phpType, $this->member($user, 'enabled')->nullable, $this->member($user, 'enabled')->default, $this->member($user, 'enabled')->required]);
        self::assertSame([UserType::class, true], [$this->member($user, 'type')->phpType, $this->member($user, 'type')->nullable]);
        self::assertTrue($this->member($user, 'userName')->required);
        self::assertFalse($this->member($user, 'createdAt')->writable);
        self::assertTrue($this->member($user, 'updatedAt')->writable);
    }

    #[Test]
    #[TestDox('The published definition lists the case values of a string-backed enum')]
    public function enumCasesBecomeCanonicalValues(): void
    {
        self::assertSame(['Employee', 'Contractor', 'Intern'], $this->member($this->load(User::class), 'type')->definition->canonicalValues);
    }

    #[Test]
    #[TestDox('A constructor parameter gets the mapping of the property with its name')]
    public function constructorParametersResolveByName(): void
    {
        $parameters = array_map(
            static fn (ConstructorParameter $parameter): array => [$parameter->name, $parameter->attribute?->declaredPath, $parameter->optional],
            $this->load(Group::class)->constructorParameters,
        );

        self::assertSame([['name', 'displayName', false]], $parameters);
    }

    #[Test]
    #[TestDox('A custom resource type keeps its options, and a factory replaces the constructor plan')]
    public function customResourceTypeKeepsOptions(): void
    {
        $device = $this->load(Device::class);

        self::assertSame(['Device', '/Devices', 'd', DeviceFactory::class, null, []], [$device->name(), $device->resourceType->endpoint, $device->idPrefix, $device->factory, $device->store, $device->constructorParameters]);
        self::assertSame(['int', TagConverter::class], [$device->id->phpType, $this->member($device, 'tag')->converter]);
    }

    #[Test]
    #[TestDox('An int meta.version gets VersionConverter, a string one no converter')]
    public function intVersionGetsVersionConverter(): void
    {
        self::assertSame([VersionConverter::class, null], [$this->member($this->load(User::class), 'version')->converter, $this->member($this->load(Team::class), 'version')->converter]);
    }

    #[Test]
    #[TestDox('A method mapping is read-only and an immutable member may be set by the constructor only')]
    public function methodIsReadOnlyAndImmutableNeedsNoSetter(): void
    {
        $device = $this->load(Device::class);

        self::assertSame([Mutability::ReadOnly, true, false], [$this->member($device, 'label')->definition->mutability, $this->member($device, 'label')->method, $this->member($device, 'label')->writable]);
        self::assertSame([Mutability::Immutable, false], [$this->member($device, 'purchasedAt')->definition->mutability, $this->member($device, 'purchasedAt')->writable]);
        self::assertSame('label()', $this->member($device, 'label')->label());
    }

    #[Test]
    #[TestDox('The implicit id is a private $id of a parent class')]
    public function implicitIdOfParentClass(): void
    {
        $id = $this->load(DerivedUser::class)->id;

        self::assertSame(['id', Uuid::class, false], [$id->member, $id->phpType, $id->writable]);
    }

    #[Test]
    #[TestDox('A public private(set) member is not writable')]
    public function privateSetIsNotWritable(): void
    {
        self::assertFalse($this->member($this->load(DerivedUser::class), 'createdAt')->writable);
    }

    /**
     * @param class-string $class
     */
    private function load(string $class): ResourceMapping
    {
        return new AttributeLoader()->load($class);
    }

    private function member(ResourceMapping $mapping, string $member): AttributeMapping
    {
        $found = array_find($mapping->attributes, static fn (AttributeMapping $attribute): bool => $attribute->member === $member);
        self::assertInstanceOf(AttributeMapping::class, $found, $member);

        return $found;
    }

    /**
     * @return array{string|null, bool, string|null, string|null}
     */
    private function access(AttributeMapping $mapping): array
    {
        return [$mapping->targetType, $mapping->writable, $mapping->adder, $mapping->remover];
    }
}
