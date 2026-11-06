<?php

declare(strict_types=1);

return [
    'doctrine' => [
        'dbal' => [
            'driver' => 'pdo_sqlite',
            'path' => '%env(DATABASE_PATH)%',
        ],
        'orm' => [
            'mappings' => [
                'ServiceProvider' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => '%kernel.project_dir%/src/Entity',
                    'prefix' => 'TacticMedia\ScimDemo\ServiceProvider\Entity',
                ],
            ],
        ],
    ],
];
