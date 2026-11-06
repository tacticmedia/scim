<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;

/**
 * For KernelTestCase tests: the ORM store on the Doctrine registry and the mapping registry of the booted test kernel.
 */
trait CreatesOrmStore
{
    use CreatesSchema;

    private static function store(): OrmObjectStore
    {
        return new OrmObjectStore(
            self::getContainer()->get('doctrine'),
            self::mappings(),
            new ServiceLocator([IdConverter::class => static fn (): ValueConverter => new IdConverter()]),
        );
    }

    private static function mappings(): MappingRegistry
    {
        return self::getContainer()->get('test.tacticmedia_scim_server.mapping_registry');
    }
}
