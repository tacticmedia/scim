<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * For KernelTestCase tests: drops every table of the DATABASE_URL database, then creates the schema.
 */
trait CreatesSchema
{
    private static function createSchema(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }
}
