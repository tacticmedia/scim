<?php

declare(strict_types=1);

use TacticMedia\ScimDemo\Tests\IdentityProvider\FakeServiceProvider;

return [
    'framework' => [
        'http_client' => ['default_options' => ['timeout' => 10]],
    ],
    'when@test' => [
        'framework' => [
            'http_client' => ['mock_response_factory' => FakeServiceProvider::class],
        ],
    ],
];
