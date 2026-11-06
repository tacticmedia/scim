<?php

declare(strict_types=1);

return [
    'tacticmedia_scim_server' => [
        'access_tokens' => [
            'identity-provider' => '%env(SCIM_TOKEN)%',
        ],
    ],
];
