<?php

declare(strict_types=1);

$databasePerTestRun = [
    'parameters' => ['env(IDP_TEST_RUN)' => 'default'],
    'doctrine' => [
        'dbal' => ['path' => '%kernel.cache_dir%/identity-provider-%env(IDP_TEST_RUN)%.sqlite'],
    ],
];

return [
    'doctrine' => [
        'dbal' => [
            'driver' => 'pdo_sqlite',
            'path' => '%kernel.project_dir%/var/identity-provider.sqlite',
        ],
        'orm' => [
            'mappings' => [
                'IdentityProvider' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => '%kernel.project_dir%/src/Entity',
                    'prefix' => 'TacticMedia\ScimDemo\IdentityProvider\Entity',
                ],
            ],
        ],
    ],
    'when@test' => $databasePerTestRun,
    'when@e2e' => $databasePerTestRun,
];
