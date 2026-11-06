<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\ServiceProvider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\BeforeClass;
use TacticMedia\ScimDemo\ServiceProvider\Kernel;

/**
 * The SP kernel with a SQLite file per test run in its cache directory and its own SCIM token.
 */
trait ServiceProviderKernel
{
    private const string TOKEN = 'service-provider-test-token';

    private static string $databaseFile;

    #[BeforeClass]
    public static function useTestRunEnvironment(): void
    {
        $databaseFile = \sprintf('%s/service-provider-%d.sqlite', new Kernel('test', true)->getCacheDir(), getmypid());
        self::$databaseFile = $databaseFile;
        $_SERVER['DATABASE_PATH'] = $databaseFile;
        $_SERVER['SCIM_TOKEN'] = self::TOKEN;
        register_shutdown_function(static fn (): bool => is_file($databaseFile) && unlink($databaseFile));
    }

    #[AfterClass]
    public static function restoreEnvironment(): void
    {
        unset($_SERVER['DATABASE_PATH'], $_SERVER['SCIM_TOKEN']);
    }

    #[\Override]
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /**
     * Starts from an empty file: dropSchema() fails on SQLite foreign keys when the metadata order puts User first.
     */
    private static function createSchema(): void
    {
        $entityManager = self::entityManager();
        $entityManager->getConnection()->close();

        if (is_file(self::$databaseFile)) {
            unlink(self::$databaseFile);
        }

        new SchemaTool($entityManager)->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    private static function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private static function save(object $entity): object
    {
        self::entityManager()->persist($entity);
        self::entityManager()->flush();

        return $entity;
    }
}
