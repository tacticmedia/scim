<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class UniqueSlot
{
    use UserBasics;

    #[ScimAttribute('emails[type eq "work"].value', uniqueness: Uniqueness::Server)]
    public ?string $email = null;
}
