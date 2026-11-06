<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class DuplicatePath
{
    use UserBasics;

    #[ScimAttribute('displayName')]
    public ?string $displayName = null;

    #[ScimAttribute('DISPLAYNAME')]
    public ?string $label = null;
}
