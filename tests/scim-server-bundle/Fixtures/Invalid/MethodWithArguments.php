<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class MethodWithArguments
{
    use UserBasics;

    #[ScimAttribute('displayName')]
    public function displayName(string $locale): string
    {
        return $locale;
    }
}
