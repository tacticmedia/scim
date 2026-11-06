<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Object\DocumentMapper;
use TacticMedia\ScimServerBundle\Object\Instantiator;
use TacticMedia\ScimServerBundle\Object\ObjectResourceRepository;
use TacticMedia\ScimServerBundle\Query\QueryPlanner;
use TacticMedia\ScimServerBundle\Validation\ObjectValidator;

/**
 * For KernelTestCase tests: repositories over the ORM store of the booted kernel for classes that the kernel does not
 * discover, and a library server over them.
 */
trait OrmRepositories
{
    use CreatesDocumentMapper;

    /**
     * @return array<string, ObjectResourceRepository> by resource type name
     */
    private static function ormRepositories(MappingRegistry $mappings, ObjectValidator $validator = new ObjectValidator()): array
    {
        $store = new OrmObjectStore(self::getContainer()->get('doctrine'), $mappings, self::converters());
        $stores = new ServiceLocator([OrmObjectStore::class => static fn (): OrmObjectStore => $store]);
        $mapper = new DocumentMapper($mappings, self::converters(), $stores);
        $repositories = [];

        foreach ($mappings->names() as $name) {
            $repositories[$name] = new ObjectResourceRepository($name, $mappings, new QueryPlanner($mappings, self::converters()), $mapper, new Instantiator(new ServiceLocator([]), $mapper), $stores, self::converters(), validator: $validator);
        }

        return $repositories;
    }

    private static function ormServer(MappingRegistry $mappings, ?EventDispatcherInterface $dispatcher = null, ObjectValidator $validator = new ObjectValidator()): ScimServer
    {
        $repositories = self::ormRepositories($mappings, $validator);

        return new ScimServer(array_map(static fn (string $name): RegisteredResourceType => new RegisteredResourceType($mappings->get($name)->resourceType, $repositories[$name]), $mappings->names()), eventDispatcher: $dispatcher);
    }
}
