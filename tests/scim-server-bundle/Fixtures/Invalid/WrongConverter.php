<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class WrongConverter
{
    use UserBasics;

    #[ScimAttribute('displayName', converter: \stdClass::class)] // @phpstan-ignore argument.type (the loader rejects it)
    public ?string $displayName = null;
}
