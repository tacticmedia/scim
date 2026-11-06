<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use TacticMedia\ScimServerBundle\Converter\HashPassword;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\NativeConverter;
use TacticMedia\ScimServerBundle\Converter\VersionConverter;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Object\DocumentMapper;
use TacticMedia\ScimServerBundle\Tests\Fixtures\ArrayObjectStore;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\TagConverter;

/**
 * A DocumentMapper with the real converters and an ArrayObjectStore in place of the Doctrine ORM store.
 */
trait CreatesDocumentMapper
{
    /**
     * The fixture entities User and Group, as the test kernel discovers them.
     */
    private static function kernelMappings(): MappingRegistry
    {
        return self::getContainer()->get('test.tacticmedia_scim_server.mapping_registry');
    }

    /**
     * @param array<string, class-string> $classes
     */
    private static function mappings(array $classes): MappingRegistry
    {
        return new MappingRegistry(new AttributeLoader(), $classes);
    }

    /**
     * @return ServiceLocator<mixed>
     */
    private static function converters(): ServiceLocator
    {
        return new ServiceLocator([
            NativeConverter::class => static fn (): NativeConverter => new NativeConverter(),
            IdConverter::class => static fn (): IdConverter => new IdConverter(),
            VersionConverter::class => static fn (): VersionConverter => new VersionConverter(),
            TagConverter::class => static fn (): TagConverter => new TagConverter(),
            HashPassword::class => static fn (): HashPassword => new HashPassword(new UserPasswordHasher(new PasswordHasherFactory([
                PasswordAuthenticatedUserInterface::class => ['algorithm' => 'bcrypt', 'cost' => 4],
            ]))),
        ]);
    }

    private static function mapper(MappingRegistry $mappings, ArrayObjectStore $store = new ArrayObjectStore()): DocumentMapper
    {
        return new DocumentMapper($mappings, self::converters(), new ServiceLocator([OrmObjectStore::class => static fn (): ArrayObjectStore => $store]));
    }
}
