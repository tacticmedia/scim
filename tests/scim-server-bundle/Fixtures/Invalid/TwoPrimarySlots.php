<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class TwoPrimarySlots
{
    use UserBasics;

    #[ScimAttribute('emails[type eq "work"].value', primary: true)]
    public ?string $workEmail = null;

    #[ScimAttribute('emails[type eq "home"].value', primary: true)]
    public ?string $homeEmail = null;
}
