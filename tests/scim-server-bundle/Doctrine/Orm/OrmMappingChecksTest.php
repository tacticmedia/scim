<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmMappingChecks;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

final class OrmMappingChecksTest extends KernelTestCase
{
    use CreatesOrmStore;
    private const string USER = User::class;
    private const string GROUP = Group::class;
    private const string VERSION_PROBLEM = 'needs an integer #[ORM\Version] field, but %s is not one. Map "meta.version" to an int property with #[ORM\Version] and #[ORM\Column(type: Types::INTEGER)].';

    /**
     * @param array{string, string|null, string, string|null} $change path, slot type, new member, new target type
     */
    #[Test]
    #[DataProvider('mismatches')]
    #[TestDox('First use fails for $_dataName')]
    public function firstUseFails(array $change, string $message): void
    {
        self::createSchema();

        $this->expectExceptionObject(new MappingException([$message]));

        self::store()->find($this->changed(...$change), 'x');
    }

    /**
     * @return iterable<string, array{array{string, string|null, string, string|null}, string}>
     */
    public static function mismatches(): iterable
    {
        yield 'a field that Doctrine does not map' => [['displayName', null, 'nickName', null], self::USER.'::$nickName: "displayName" needs a Doctrine field, but $nickName is not mapped by Doctrine, so writes to it are lost. Add #[ORM\Column] to $nickName.'];
        yield 'a slot on an association' => [['phoneNumbers.value', 'mobile', 'manager', null], self::USER.'::$manager: "phoneNumbers[type eq "mobile"].value" needs a Doctrine field, but $manager is an association. Map a column, or map the association to a reference attribute.'];
        yield 'a reference on a column' => [['manager', null, 'displayName', null], self::USER.'::$displayName: "manager" refers to User, but $displayName is not a to-one Doctrine association to '.self::USER.'. Map $displayName with #[ORM\ManyToOne] or #[ORM\OneToOne] to '.self::USER.'.'];
        yield 'a reference to another class' => [['manager', null, 'manager', 'Group'], self::USER.'::$manager: "manager" refers to Group, but $manager is not a to-one Doctrine association to '.self::GROUP.'. Map $manager with #[ORM\ManyToOne] or #[ORM\OneToOne] to '.self::GROUP.'.'];
        yield 'a reference collection on a to-one association' => [['groups', null, 'manager', null], self::USER.'::$manager: "groups" refers to Group, but $manager is not a to-many Doctrine association to '.self::GROUP.'. Map $manager with #[ORM\ManyToMany] or #[ORM\OneToMany] to '.self::GROUP.'.'];
        yield 'meta.version on a column that is not the version' => [['meta.version', null, 'enabled', null], self::USER.'::$enabled: "meta.version" '.\sprintf(self::VERSION_PROBLEM, '$enabled')];
        yield 'an id that is not the Doctrine identifier' => [['id', null, 'userName', null], self::USER.': "id" maps $userName, but the Doctrine identifier is $id. Map "id" to the single Doctrine identifier field.'];
    }

    #[Test]
    #[TestDox('meta.version on a datetime version field fails')]
    public function datetimeVersionFails(): void
    {
        $metadata = clone self::createSchema()->getClassMetadata(self::USER);
        $metadata->fieldMappings['version'] = clone $metadata->fieldMappings['version'];
        $metadata->fieldMappings['version']->type = 'datetime_immutable';

        $this->expectExceptionObject(new MappingException([self::USER.'::$version: "meta.version" '.\sprintf(self::VERSION_PROBLEM, '$version')]));

        OrmMappingChecks::check(self::mappings()->get('User'), $metadata, self::mappings());
    }

    #[Test]
    #[TestDox('#[ScimSoftDelete] that writes a DateTimeImmutable into a datetime column fails')]
    public function mutableSoftDeleteColumnFails(): void
    {
        $metadata = clone self::createSchema()->getClassMetadata(self::USER);
        $metadata->fieldMappings['deletedAt'] = clone $metadata->fieldMappings['deletedAt'];
        $metadata->fieldMappings['deletedAt']->type = 'datetime';

        $this->expectExceptionObject(new MappingException([self::USER.'::$deletedAt: #[ScimSoftDelete] writes a DateTimeImmutable, but $deletedAt is a datetime column, which takes a DateTime. Type $deletedAt as ?\DateTime, or map it to the datetime_immutable type.']));

        OrmMappingChecks::check(self::mappings()->get('User'), $metadata, self::mappings());
    }

    #[Test]
    #[TestDox('One exception lists every mismatch of a mapping')]
    public function listsEveryMismatch(): void
    {
        self::createSchema();
        $mapping = $this->changed('displayName', null, 'nickName', null);
        $mapping = $this->replaced($mapping, $this->at($mapping, 'externalId', null), 'alias', null);

        $this->expectExceptionObject(new MappingException([
            self::USER.'::$alias: "externalId" needs a Doctrine field, but $alias is not mapped by Doctrine, so writes to it are lost. Add #[ORM\Column] to $alias.',
            self::USER.'::$nickName: "displayName" needs a Doctrine field, but $nickName is not mapped by Doctrine, so writes to it are lost. Add #[ORM\Column] to $nickName.',
        ]));

        self::store()->find($mapping, 'x');
    }

    #[Test]
    #[TestDox('A computed mapping needs no Doctrine field, and valid mappings pass')]
    public function computedAndValidMappingsPass(): void
    {
        self::createSchema();
        $users = self::mappings()->get('User');
        $displayName = $this->at($users, 'displayName', null);
        $computed = new AttributeMapping($displayName->path, $displayName->declaredPath, $displayName->definition, MappingKind::Computed, 'getDisplayName', true, 'string', true, false, false);
        $withComputed = new ResourceMapping($users->class, $users->resourceType, $users->id, array_map(static fn (AttributeMapping $attribute): AttributeMapping => $attribute === $displayName ? $computed : $attribute, $users->attributes));

        self::assertNull(self::store()->find($withComputed, 'x'));
        self::assertNull(self::store()->find(self::mappings()->get('Group'), 'x'));
    }

    /**
     * The User mapping with the mapping at $path moved to $member and, when given, to $targetType.
     */
    private function changed(string $path, ?string $type, string $member, ?string $targetType): ResourceMapping
    {
        $users = self::mappings()->get('User');

        return $this->replaced($users, $this->at($users, $path, $type), $member, $targetType);
    }

    private function replaced(ResourceMapping $mapping, AttributeMapping $old, string $member, ?string $targetType): ResourceMapping
    {
        $new = new AttributeMapping($old->path, $old->declaredPath, $old->definition, $old->kind, $member, $old->method, $old->phpType, $old->nullable, $old->writable, $old->required, $old->adder, $old->remover, $old->converter, $old->type, $old->primary, $targetType ?? $old->targetType, $old->default);
        $swap = static fn (AttributeMapping $attribute): AttributeMapping => $attribute === $old ? $new : $attribute;

        return new ResourceMapping($mapping->class, $mapping->resourceType, $swap($mapping->id), array_map($swap, $mapping->attributes));
    }

    private function at(ResourceMapping $mapping, string $path, ?string $type): AttributeMapping
    {
        $found = array_find($mapping->attributes, static fn (AttributeMapping $attribute): bool => 0 === strcasecmp($attribute->path->attribute.(null === $attribute->path->subAttribute ? '' : '.'.$attribute->path->subAttribute), $path) && $attribute->type === $type);
        self::assertInstanceOf(AttributeMapping::class, $found);

        return $found;
    }
}
