<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class WrongIdType
{
    public float $id = 0.0;

    #[ScimAttribute('userName')]
    public string $userName = '';
}
