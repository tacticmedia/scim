<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use TacticMedia\ScimServerBundle\DependencyInjection\Compiler\RepositoryPass;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\TacticMediaScimServerBundle;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\TenantGroup;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\TenantUser;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\PrinterRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\LdapUserRepository;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Account;

/**
 * Compiles a container with the bundle and the given classes, and records what RepositoryPass registered.
 */
final class RepositoryPassTest extends KernelTestCase
{
    #[Test]
    #[TestDox('Each mapped class and raw repository gets a tagged repository service and a server entry; debug:scim gets the mapped ones')]
    public function repositoriesAreRegistered(): void
    {
        $recorded = $this->compile([], [], Group::class, User::class, PrinterRepository::class);
        $server = $recorded->definition('tacticmedia_scim_server.server');
        $debugRepositories = $recorded->definition('tacticmedia_scim_server.command.debug')->getArgument(6);

        self::assertSame([
            PrinterRepository::class => [['name' => 'Printer']],
            'tacticmedia_scim_server.repository.group' => [['name' => 'Group']],
            'tacticmedia_scim_server.repository.user' => [['name' => 'User']],
        ], $recorded->tagged);
        self::assertSame(PrinterRepository::class, $recorded->aliases['tacticmedia_scim_server.repository.printer']);
        self::assertSame(['Printer' => PrinterRepository::class], $recorded->parameters[RepositoryPass::REPOSITORY_CLASSES_PARAMETER]);
        self::assertSame(
            ['tacticmedia_scim_server.repository.group', 'tacticmedia_scim_server.repository.user', 'tacticmedia_scim_server.repository.printer'],
            array_map(static fn (Definition $registered): string => (string) $registered->getArgument(1), $this->list($server->getArgument(0))),
        );
        self::assertInstanceOf(ServiceLocatorArgument::class, $debugRepositories);
        self::assertSame(['Group' => 'tacticmedia_scim_server.repository.group', 'User' => 'tacticmedia_scim_server.repository.user'], array_map(strval(...), $debugRepositories->getValues()));
    }

    #[Test]
    #[TestDox('The ServiceProviderConfig and the access_tokens parameter come from the configuration')]
    public function serviceProviderConfigFollowsTheConfiguration(): void
    {
        $recorded = $this->compile(['max_results' => 50, 'bulk' => ['enabled' => false, 'max_operations' => 10], 'access_tokens' => ['entra' => 'secret']], [], Group::class, User::class);
        $config = $recorded->definition('tacticmedia_scim_server.service_provider_config');

        self::assertSame([50, false, 10, 1048576, true], [$config->getArgument('$maxResults'), $config->getArgument('$bulkSupported'), $config->getArgument('$bulkMaxOperations'), $config->getArgument('$bulkMaxPayloadSizeBytes'), $config->getArgument('$changePasswordSupported')]);
        self::assertSame(['entra' => 'secret'], $recorded->parameters['tacticmedia_scim_server.access_tokens']);
    }

    #[Test]
    #[TestDox('changePassword is supported when a mapping writes password')]
    public function changePasswordFollowsTheMappings(): void
    {
        self::assertTrue($this->compile([], [], Account::class)->definition('tacticmedia_scim_server.service_provider_config')->getArgument('$changePasswordSupported'));
    }

    #[Test]
    #[TestDox('The ORM store loads only when DoctrineBundle is registered')]
    public function ormStoreNeedsDoctrineBundle(): void
    {
        $withDoctrine = $this->compile([], ['kernel.bundles' => ['DoctrineBundle' => DoctrineBundle::class]]);

        self::assertSame(['doctrine', 'tacticmedia_scim_server.mapping_registry', 'tacticmedia_scim_server.converter_locator'], array_map(strval(...), $withDoctrine->definition('tacticmedia_scim_server.object_store.orm')->getArguments()));
        self::assertArrayNotHasKey('tacticmedia_scim_server.object_store.orm', $this->compile([], ['kernel.bundles' => []])->definitions);
    }

    #[Test]
    #[TestDox('A raw repository with the name and endpoint of a mapped class fails the build')]
    public function rawRepositoryNamesMustBeUnique(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf("\n- %s and %2\$s use one resource type name. Give each its own name and endpoint.\n- %1\$s and %2\$s use one resource type endpoint. Give each its own name and endpoint.", User::class, LdapUserRepository::class));

        $this->compile([], [], Group::class, User::class, LdapUserRepository::class);
    }

    #[Test]
    #[TestDox('Two types that publish one schema differently fail the build')]
    public function sharedSchemaMustBeEqual(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('%s and %s publish the schema urn:acme:scim:schemas:extension:tenant:1.0 differently. Map the same attributes the same way in each, or give each its own schema URN.', TenantUser::class, TenantGroup::class));

        $this->compile([], [], TenantUser::class, TenantGroup::class);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $parameters
     * @param class-string         ...$classes
     */
    private function compile(array $config, array $parameters, string ...$classes): RecordingPass
    {
        $container = new ContainerBuilder();
        $container->getParameterBag()->add($parameters);
        $bundle = new TacticMediaScimServerBundle();
        $bundle->build($container);

        $recorder = new RecordingPass();
        $container->addCompilerPass($recorder, priority: -10);

        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);

        foreach ($classes as $class) {
            $container->register($class, $class)->setAutoconfigured(true);
        }

        $container->compile(true);

        return $recorder;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        self::assertIsList($value);

        return $value;
    }
}
