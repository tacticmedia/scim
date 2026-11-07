<?php

declare(strict_types=1);

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return [
    'framework' => [
        'secret' => '%env(APP_SECRET)%',
        'csrf_protection' => true,
        'form' => true,
        'validation' => true,
        'session' => true,
        'exceptions' => [
            AccessDeniedHttpException::class => ['log_level' => 'notice'],
        ],
    ],
    'when@test' => [
        'framework' => [
            'test' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ],
    ],
    'when@e2e' => [
        'framework' => ['test' => true],
    ],
];
