<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use TacticMedia\ScimServerBundle\EventListener\RateLimitListener;
use TacticMedia\ScimServerBundle\TacticMediaScimServerBundle;

/**
 * The rate_limit and me options, as services after loadExtension().
 */
final class OptionalFeaturesTest extends KernelTestCase
{
    private const string LISTENER = 'tacticmedia_scim_server.rate_limit_listener';
    private const string LIMITER = 'tacticmedia_scim_server.rate_limiter';
    private const string RESOLVER = 'tacticmedia_scim_server.me_resolver';
    private const string SERVER = 'tacticmedia_scim_server.server';

    #[Test]
    #[TestDox('Without configuration, no rate limit and no /Me resolver')]
    public function bothAreOffByDefault(): void
    {
        $container = $this->load([]);

        self::assertSame([false, false, false], [$container->hasDefinition(self::LISTENER), $container->hasDefinition(self::LIMITER), $container->hasDefinition(self::RESOLVER)]);
        self::assertArrayNotHasKey(3, $container->getDefinition(self::SERVER)->getArguments());
    }

    #[Test]
    #[TestDox('rate_limit: a token bucket in cache.rate_limiter, with lock.factory if any, keyed after the firewall')]
    public function rateLimitBuildsATokenBucket(): void
    {
        $container = $this->load(['rate_limit' => ['limit' => 100]]);
        $limiter = $container->getDefinition(self::LIMITER);
        $storage = $limiter->getArgument(1);
        $listener = $container->getDefinition(self::LISTENER);

        self::assertSame(RateLimiterFactory::class, $limiter->getClass());
        self::assertSame(['id' => 'tacticmedia_scim_server', 'policy' => 'token_bucket', 'limit' => 100, 'rate' => ['interval' => '1 second', 'amount' => 50]], $limiter->getArgument(0));
        self::assertInstanceOf(Definition::class, $storage);
        self::assertSame([CacheStorage::class, 'cache.rate_limiter'], [$storage->getClass(), (string) $storage->getArgument(0)]);
        self::assertSame(['lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE], $this->reference($limiter->getArgument(2)));
        self::assertSame(RateLimitListener::class, $listener->getClass());
        self::assertSame([[self::LIMITER, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE], ['security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE]], array_map($this->reference(...), $listener->getArguments()));
        self::assertSame([['event' => 'kernel.request', 'priority' => 7]], $listener->getTag('kernel.event_listener'));
    }

    #[Test]
    #[TestDox('rate_limit.limiter replaces the bucket options with a limiter service')]
    public function rateLimitUsesTheGivenLimiter(): void
    {
        $container = $this->load(['rate_limit' => ['limiter' => 'limiter.scim', 'cache_pool' => 'cache.redis']]);

        self::assertFalse($container->hasDefinition(self::LIMITER));
        self::assertSame(['limiter.scim', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE], $this->reference($container->getDefinition(self::LISTENER)->getArgument(0)));
    }

    #[Test]
    #[TestDox('me.resolver gives the server an application MeResolver')]
    public function meUsesTheGivenResolver(): void
    {
        $container = $this->load(['me' => ['resolver' => 'app.me_resolver']]);

        self::assertFalse($container->hasDefinition(self::RESOLVER));
        self::assertSame(['app.me_resolver', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE], $this->reference($container->getDefinition(self::SERVER)->getArgument(3)));
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

    /**
     * @return array{string, int}
     */
    private function reference(mixed $reference): array
    {
        self::assertInstanceOf(Reference::class, $reference);

        return [(string) $reference, $reference->getInvalidBehavior()];
    }
}
