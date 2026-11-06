<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class IntUser
{
    public int $id = 0;

    #[ScimAttribute('userName')]
    public string $userName = '';
}
