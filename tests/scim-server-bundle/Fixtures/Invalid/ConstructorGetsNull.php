<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class ConstructorGetsNull
{
    use UserBasics;

    #[ScimAttribute('displayName')]
    public ?string $displayName;

    public function __construct(string $displayName)
    {
        $this->displayName = $displayName;
    }
}
