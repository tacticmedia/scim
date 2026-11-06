<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class ComputedTypeMismatch
{
    use UserBasics;

    #[ScimAttribute('active')]
    public function active(): string
    {
        return 'yes';
    }
}
