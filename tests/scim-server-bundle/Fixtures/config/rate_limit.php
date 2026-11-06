<?php

declare(strict_types=1);

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // Not a cache.pool, so the kernel does not reset it between requests; a new kernel starts with full buckets.
    $container->services()->set('test.rate_limit_storage', ArrayAdapter::class);
    $container->extension('tacticmedia_scim_server', [
        'rate_limit' => ['limit' => 2, 'rate' => ['interval' => '1 hour', 'amount' => 1], 'cache_pool' => 'test.rate_limit_storage'],
    ]);
};
