<?php

declare(strict_types=1);

use Symfony\Component\HttpKernel\Exception\HttpException;

return [
    'framework' => [
        'secret' => '%env(APP_SECRET)%',
    ],
    'when@test' => [
        'framework' => [
            'test' => true,
            'exceptions' => [
                HttpException::class => ['log_level' => 'notice'],
            ],
        ],
    ],
];
