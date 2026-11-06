<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', idPrefix: 'u1')]
final class IdPrefixEndsInDigit
{
    public int $id = 0;

    #[ScimAttribute('userName')]
    public string $userName = '';
}
