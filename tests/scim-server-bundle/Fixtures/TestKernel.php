<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Kernel\BundleInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use TacticMedia\ScimServerBundle\TacticMediaScimServerBundle;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;
    public const string ENTRA_TOKEN = 'entra-secret-token';
    public const string OKTA_TOKEN = 'okta-secret-token';

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new TacticMediaScimServerBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'scim-server-bundle',
            'test' => true,
        ]);

        $container->parameters()->set('env(DATABASE_URL)', 'sqlite:///:memory:');
        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(resolve:DATABASE_URL)%',
                // As docs/index.md, section Database notes, tells an application on MySQL or MariaDB.
                'default_table_options' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin'],
            ],
            'orm' => [
                'mappings' => [
                    'Fixtures' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__.'/Entity',
                        'prefix' => __NAMESPACE__.'\Entity',
                    ],
                ],
            ],
        ]);

        // As docs/index.md tells an application.
        $container->extension('tacticmedia_scim_server', [
            'access_tokens' => ['entra' => self::ENTRA_TOKEN, 'okta' => self::OKTA_TOKEN],
        ]);
        $container->extension('security', [
            'password_hashers' => [PasswordAuthenticatedUserInterface::class => ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10]],
            'firewalls' => [
                'scim' => [
                    'pattern' => '^/scim/v2',
                    'stateless' => true,
                    'access_token' => [
                        'token_handler' => 'tacticmedia_scim_server.access_token_handler',
                        'token_extractors' => 'tacticmedia_scim_server.access_token_extractor',
                        'failure_handler' => 'tacticmedia_scim_server.authentication_failure_handler',
                    ],
                ],
            ],
            'access_control' => [
                ['path' => '^/scim/v2', 'roles' => 'ROLE_SCIM_CLIENT'],
            ],
        ]);

        $services = $container->services();
        $services->defaults()->autoconfigure();
        $services->load(__NAMESPACE__.'\Entity\\', __DIR__.'/Entity/');
        $services->load(__NAMESPACE__.'\Repository\\', __DIR__.'/Repository/')->autowire();
        $services->load(__NAMESPACE__.'\EventListener\\', __DIR__.'/EventListener/');
        // The default logger writes the errors of the error-path tests to stderr.
        $services->set('logger', NullLogger::class);

        // Tests fetch these services, which no other service uses.
        foreach (['mapping_registry', 'attribute_loader', 'schema_builder', 'server', 'repository.user', 'repository.group', 'repository.badge', 'repository.printer', 'object_validator'] as $service) {
            $services->alias('test.tacticmedia_scim_server.'.$service, 'tacticmedia_scim_server.'.$service)->public();
        }

        // Another environment adds its options, for example createKernel(['environment' => 'me']) loads config/me.php.
        if ('test' !== $this->environment) {
            $container->import(__DIR__.'/config/'.$this->environment.'.php');
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@TacticMediaScimServerBundle/config/routes.php')->prefix('/scim/v2');
    }
}
