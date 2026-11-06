<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('Group')]
final class UniqueMethodGroup
{
    public string $id = '';

    #[ScimAttribute('displayName')]
    public function displayName(): string
    {
        return $this->id;
    }
}
