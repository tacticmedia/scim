<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\HiddenUserFilter;

return static function (ContainerConfigurator $container): void {
    $container->extension('doctrine', [
        'orm' => [
            'filters' => [
                'hidden_user' => ['class' => HiddenUserFilter::class, 'enabled' => true, 'parameters' => ['userName' => 'bjensen']],
            ],
        ],
    ]);
};
