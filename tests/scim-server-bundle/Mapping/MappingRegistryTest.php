<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\UserProxy;

final class MappingRegistryTest extends KernelTestCase
{
    #[Test]
    #[TestDox('get() loads a mapping once and keeps it')]
    public function getLoadsOnce(): void
    {
        $registry = $this->registry();

        self::assertSame(['User', 'Group'], $registry->names());
        self::assertSame(User::class, $registry->get('User')->class);
        self::assertSame($registry->get('User'), $registry->get('User'));
    }

    #[Test]
    #[TestDox('forClass() finds the mapping of a class and of its subclass')]
    public function forClassFindsSubclasses(): void
    {
        $registry = $this->registry();

        self::assertSame($registry->get('Group'), $registry->forClass(Group::class));
        self::assertSame($registry->get('User'), $registry->forClass(UserProxy::class));
    }

    #[Test]
    #[TestDox('An unknown name fails with the mapped names')]
    public function unknownNameFails(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains('No class maps the resource type "Device". Mapped: User, Group.');

        $this->registry()->get('Device');
    }

    #[Test]
    #[TestDox('An unmapped class fails')]
    public function unmappedClassFails(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains('stdClass is not mapped by #[ScimResource].');

        $this->registry()->forClass(\stdClass::class);
    }

    private function registry(): MappingRegistry
    {
        return new MappingRegistry(new AttributeLoader(), ['User' => User::class, 'Group' => Group::class]);
    }
}
