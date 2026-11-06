<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use TacticMedia\ScimServerBundle\TacticMediaScimServerBundle;

/**
 * The pagination option, as arguments of the server and the ServiceProviderConfig after loadExtension().
 */
final class PaginationConfigurationTest extends KernelTestCase
{
    #[Test]
    #[TestDox('By default, the server gets kernel.secret as cursor key and the clock service, and cursors do not expire')]
    public function cursorsAreOnByDefault(): void
    {
        $container = $this->load([]);
        $server = $container->getDefinition('tacticmedia_scim_server.server');
        $clock = $server->getArgument('$clock');

        self::assertEquals(new Parameter('kernel.secret'), $server->getArgument('$cursorKey'));
        self::assertInstanceOf(Reference::class, $clock);
        self::assertSame(['clock', ContainerInterface::NULL_ON_INVALID_REFERENCE], [(string) $clock, $clock->getInvalidBehavior()]);
        self::assertNull($container->getDefinition('tacticmedia_scim_server.service_provider_config')->getArgument('$cursorTimeoutSeconds'));
    }

    #[Test]
    #[TestDox('pagination.cursor.key and timeout_seconds reach the server and the ServiceProviderConfig')]
    public function keyAndTimeoutAreConfigurable(): void
    {
        $container = $this->load(['pagination' => ['cursor' => ['key' => '%env(SCIM_CURSOR_KEY)%', 'timeout_seconds' => 300]]]);

        self::assertEquals(new Parameter(TacticMediaScimServerBundle::CURSOR_KEY_PARAMETER), $container->getDefinition('tacticmedia_scim_server.server')->getArgument('$cursorKey'));
        self::assertSame('%env(SCIM_CURSOR_KEY)%', $container->getParameter(TacticMediaScimServerBundle::CURSOR_KEY_PARAMETER));
        self::assertSame(300, $container->getDefinition('tacticmedia_scim_server.service_provider_config')->getArgument('$cursorTimeoutSeconds'));
    }

    #[Test]
    #[TestDox('pagination.cursor: false gives the server no cursor key')]
    public function cursorsCanBeDisabled(): void
    {
        $container = $this->load(['pagination' => ['cursor' => false]]);
        $arguments = $container->getDefinition('tacticmedia_scim_server.server')->getArguments();

        self::assertArrayNotHasKey('$cursorKey', $arguments);
        self::assertArrayNotHasKey(TacticMediaScimServerBundle::CURSOR_KEY_PARAMETER, $container->getParameterBag()->all());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = new TacticMediaScimServerBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
