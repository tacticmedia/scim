<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class WholeAndSlot
{
    use UserBasics;

    #[ScimAttribute('emails[type eq "work"].value')]
    public ?string $email = null;

    /**
     * @return list<array{value: string}>
     */
    #[ScimAttribute('emails')]
    public function emails(): array
    {
        return [];
    }
}
