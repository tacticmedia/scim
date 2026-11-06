<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class NotWritable
{
    use UserBasics;

    public function __construct(
        #[ScimAttribute('displayName')]
        private readonly ?string $displayName = null,
    ) {
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }
}
