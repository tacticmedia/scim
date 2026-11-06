<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class FilterOnReference
{
    use UserBasics;

    #[ScimAttribute('manager[type eq "User"]')]
    public ?self $manager = null;
}
