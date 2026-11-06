<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class MethodWithFilter
{
    use UserBasics;

    #[ScimAttribute('emails[type eq "work"].value')]
    public function email(): string
    {
        return '';
    }
}
