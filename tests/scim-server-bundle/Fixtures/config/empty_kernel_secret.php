<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('env(SCIM_KERNEL_SECRET)', '');
    $container->extension('framework', ['secret' => '%env(SCIM_KERNEL_SECRET)%']);
};
