<?php

declare(strict_types=1);

use Doctrine\DBAL\Driver\AbstractSQLiteDriver\Middleware\EnableForeignKeys;
use Psr\Clock\ClockInterface;
use TacticMedia\ScimDemo\Tests\IdentityProvider\FakeServiceProvider;

return [
    'parameters' => [
        'env(SCIM_BASE_URL)' => 'http://127.0.0.1:8001/scim/v2',
        'env(SCIM_TOKEN)' => 'demo-token',
        'env(SCIM_DIALECT)' => 'entra',
    ],
    'services' => [
        '_defaults' => ['autowire' => true, 'autoconfigure' => true],
        'TacticMedia\ScimDemo\IdentityProvider\\' => [
            'resource' => '../src/',
            'exclude' => ['../src/Kernel.php', '../src/Entity/'],
        ],
        EnableForeignKeys::class => null,
        ClockInterface::class => ['alias' => 'clock'],
    ],
    'when@test' => [
        'services' => [
            FakeServiceProvider::class => ['public' => true],
        ],
    ],
];
