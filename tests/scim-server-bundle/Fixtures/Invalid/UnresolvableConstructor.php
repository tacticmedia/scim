<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class UnresolvableConstructor
{
    use UserBasics;

    public function __construct(public string $tenant)
    {
    }
}
