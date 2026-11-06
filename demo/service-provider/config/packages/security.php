<?php

declare(strict_types=1);

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

return [
    'security' => [
        'password_hashers' => [
            PasswordAuthenticatedUserInterface::class => 'auto',
        ],
        'firewalls' => [
            'scim' => [
                'pattern' => '^/scim/v2',
                'stateless' => true,
                'access_token' => [
                    'token_handler' => 'tacticmedia_scim_server.access_token_handler',
                    'token_extractors' => 'tacticmedia_scim_server.access_token_extractor',
                    'failure_handler' => 'tacticmedia_scim_server.authentication_failure_handler',
                ],
            ],
        ],
        'access_control' => [
            ['path' => '^/scim/v2', 'roles' => 'ROLE_SCIM_CLIENT'],
        ],
    ],
    'when@test' => [
        'security' => [
            'password_hashers' => [
                PasswordAuthenticatedUserInterface::class => ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10],
            ],
        ],
    ],
];
