<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\SoftDelete;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\SoftDeleteChild;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\SoftDeleteFlag;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\SoftDeleteMapped;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\SoftDeleteNotNullable;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\SoftDeleteParent;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\SoftDeleteReadonly;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\SoftDeleteTime;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\SoftDeleteWrongType;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\TwoSoftDeletes;

final class SoftDeleteMappingTest extends KernelTestCase
{
    private const string WRONG_TYPE = '#[ScimSoftDelete] needs bool or a nullable DateTimeInterface, the member is %s. Type $%s as bool or ?\DateTimeImmutable.';

    #[Test]
    #[TestDox('#[ScimSoftDelete] compiles on a bool, a nullable DateTime and a private property of a parent; revive defaults to false')]
    public function compiles(): void
    {
        self::assertEquals(new SoftDelete(SoftDeleteFlag::class, 'deleted', null, false), new AttributeLoader()->load(SoftDeleteFlag::class)->softDelete);
        self::assertEquals(new SoftDelete(SoftDeleteTime::class, 'deletedAt', \DateTime::class, true), new AttributeLoader()->load(SoftDeleteTime::class)->softDelete);
        self::assertEquals(new SoftDelete(SoftDeleteParent::class, 'deletedAt', \DateTimeImmutable::class, false), new AttributeLoader()->load(SoftDeleteChild::class)->softDelete);
        self::assertNull(new AttributeLoader()->load(Group::class)->softDelete);
    }

    #[Test]
    #[TestDox('A bool flag is true when deleted and false when restored')]
    public function flagMarksTheObject(): void
    {
        $softDelete = new AttributeLoader()->load(SoftDeleteFlag::class)->softDelete;
        $object = new SoftDeleteFlag();
        self::assertInstanceOf(SoftDelete::class, $softDelete);

        self::assertFalse($softDelete->isDeleted($object));
        $softDelete->delete($object, new \DateTimeImmutable('2026-11-06 10:00:00'));
        self::assertSame([true, true], [$object->deleted, $softDelete->isDeleted($object)]);
        $softDelete->restore($object);
        self::assertFalse($object->deleted);
    }

    #[Test]
    #[TestDox('A date gets the given time in the class of the property and null when restored')]
    public function dateMarksTheObject(): void
    {
        $now = new \DateTimeImmutable('2026-11-06 10:00:00', new \DateTimeZone('UTC'));
        $time = new SoftDeleteTime();
        $child = new SoftDeleteChild();
        $timeSoftDelete = new AttributeLoader()->load(SoftDeleteTime::class)->softDelete;
        $childSoftDelete = new AttributeLoader()->load(SoftDeleteChild::class)->softDelete;
        self::assertInstanceOf(SoftDelete::class, $timeSoftDelete);
        self::assertInstanceOf(SoftDelete::class, $childSoftDelete);

        $timeSoftDelete->delete($time, $now);
        $childSoftDelete->delete($child, $now);

        self::assertEquals(\DateTime::createFromImmutable($now), $time->deletedAt);
        self::assertEquals($now, $child->deletedAt());
        self::assertTrue($childSoftDelete->isDeleted($child));
        $childSoftDelete->restore($child);
        self::assertNull($child->deletedAt());
        self::assertFalse($childSoftDelete->isDeleted($child));
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function invalidClasses(): iterable
    {
        yield 'a nullable bool' => [SoftDeleteWrongType::class, '%s::$deleted: '.\sprintf(self::WRONG_TYPE, '?bool', 'deleted')];
        yield 'a date that is not nullable' => [SoftDeleteNotNullable::class, '%s::$deletedAt: '.\sprintf(self::WRONG_TYPE, 'DateTimeImmutable', 'deletedAt')];
        yield 'a readonly property' => [SoftDeleteReadonly::class, '%s::$deleted: #[ScimSoftDelete] is on a readonly property, so DELETE cannot set it. Remove readonly.'];
        yield 'two properties' => [TwoSoftDeletes::class, '%s: #[ScimSoftDelete] is on $deleted and $deletedAt. Keep it on one property.'];
        yield 'a mapped property' => [SoftDeleteMapped::class, '%s::$active: #[ScimSoftDelete] is on a property that #[ScimAttribute] maps, so a write could delete or restore the object. Use a property without #[ScimAttribute].'];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('invalidClasses')]
    #[TestDox('$_dataName fails the build')]
    public function invalidClassFails(string $class, string $error): void
    {
        $this->expectExceptionObject(new MappingException([\sprintf($error, $class)]));

        new AttributeLoader()->load($class);
    }
}
