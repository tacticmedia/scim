<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class PrimaryOnField
{
    use UserBasics;

    #[ScimAttribute('displayName', primary: true)]
    public ?string $displayName = null;
}
