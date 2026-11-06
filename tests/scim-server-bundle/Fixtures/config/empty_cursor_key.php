<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('env(SCIM_CURSOR_KEY)', '');
    $container->extension('tacticmedia_scim_server', ['pagination' => ['cursor' => ['key' => '%env(SCIM_CURSOR_KEY)%']]]);
};
