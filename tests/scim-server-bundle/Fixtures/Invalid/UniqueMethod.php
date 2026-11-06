<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class UniqueMethod
{
    use UserBasics;

    #[ScimAttribute('displayName', uniqueness: Uniqueness::Server)]
    public function displayName(): string
    {
        return $this->userName;
    }
}
