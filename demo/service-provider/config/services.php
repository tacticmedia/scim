<?php

declare(strict_types=1);

use Doctrine\DBAL\Driver\AbstractSQLiteDriver\Middleware\EnableForeignKeys;

return [
    'parameters' => [
        'env(DATABASE_PATH)' => '%kernel.project_dir%/var/service-provider.sqlite',
        'env(SCIM_TOKEN)' => 'demo-token',
    ],
    'services' => [
        '_defaults' => ['autowire' => true, 'autoconfigure' => true],
        'TacticMedia\ScimDemo\ServiceProvider\\' => [
            'resource' => '../src/',
            'exclude' => ['../src/Kernel.php'],
        ],
        EnableForeignKeys::class => null,
    ],
];
