<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\BeforeClass;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;
use TacticMedia\ScimDemo\IdentityProvider\Entity\ProvisioningSettings;
use TacticMedia\ScimDemo\IdentityProvider\Kernel;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningSettingsRepository;

/**
 * The IdP kernel with a SQLite file per test run in its cache directory.
 */
trait IdentityProviderKernel
{
    #[BeforeClass]
    public static function useTestRunDatabase(): void
    {
        $testRun = (string) getmypid();
        $_SERVER['IDP_TEST_RUN'] = $testRun;
        $databaseFile = \sprintf('%s/identity-provider-%s.sqlite', new Kernel('test', true)->getCacheDir(), $testRun);
        register_shutdown_function(static fn (): bool => is_file($databaseFile) && unlink($databaseFile));
    }

    #[\Override]
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    private static function createSchema(): void
    {
        $entityManager = self::service(EntityManagerInterface::class);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    private static function fakeServiceProvider(): FakeServiceProvider
    {
        return self::service(FakeServiceProvider::class);
    }

    private static function useSettings(Dialect $dialect, string $token = FakeServiceProvider::TOKEN, string $serviceProviderUrl = FakeServiceProvider::BASE_URL): void
    {
        self::save(new ProvisioningSettings($serviceProviderUrl, $token, $dialect));
    }

    private static function useToken(string $token): void
    {
        $settings = self::service(ProvisioningSettingsRepository::class)->current();
        $settings->token = $token;
        self::save($settings);
    }

    private static function newUser(string $givenName, string $familyName, ?DirectoryUser $manager = null): DirectoryUser
    {
        $user = new DirectoryUser();
        $user->userName = strtolower($givenName.'.'.$familyName).'@example.com';
        $user->givenName = $givenName;
        $user->familyName = $familyName;
        $user->displayName = $givenName.' '.$familyName;
        $user->workEmail = $user->userName;
        $user->department = 'Engineering';
        $user->manager = $manager;

        return self::save($user);
    }

    private static function newGroup(string $displayName, DirectoryUser ...$members): DirectoryGroup
    {
        $group = new DirectoryGroup();
        $group->displayName = $displayName;

        foreach ($members as $member) {
            $group->members->add($member);
        }

        return self::save($group);
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
        $entityManager = self::service(EntityManagerInterface::class);
        $entityManager->persist($entity);
        $entityManager->flush();

        return $entity;
    }

    /**
     * @param list<array{string, array<mixed>|null}> $expected request lines relative to the base URL, and bodies
     */
    private static function assertRequests(array $expected): void
    {
        self::assertSame($expected, array_map(static fn (array $request): array => [$request['line'], $request['body']], self::fakeServiceProvider()->requests));
    }
}
