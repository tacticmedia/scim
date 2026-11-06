<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class DefaultOnRequired
{
    public string $id = '';

    #[ScimAttribute('userName', default: 'anonymous')]
    public string $userName = '';
}
