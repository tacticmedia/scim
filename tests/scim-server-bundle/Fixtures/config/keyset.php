<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('doctrine', [
        'orm' => [
            'mappings' => [
                'Keyset' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => \dirname(__DIR__, 2).'/Doctrine/Orm/Fixtures',
                    'prefix' => 'TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures',
                ],
            ],
        ],
    ]);
};
