<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class MultiValuedWithoutFilter
{
    use UserBasics;

    #[ScimAttribute('emails.value')]
    public ?string $email = null;
}
