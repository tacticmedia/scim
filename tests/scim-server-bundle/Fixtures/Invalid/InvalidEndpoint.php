<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', endpoint: 'Users')]
final class InvalidEndpoint
{
    use UserBasics;
}
