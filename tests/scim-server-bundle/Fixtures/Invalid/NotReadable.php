<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class NotReadable
{
    use UserBasics;

    #[ScimAttribute('displayName')]
    private ?string $displayName = null;

    public function setDisplayName(?string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function hasValue(): bool
    {
        return null !== $this->displayName;
    }
}
