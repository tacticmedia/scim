<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class UniquenessOnCommon
{
    use UserBasics;

    #[ScimAttribute('externalId', uniqueness: Uniqueness::Server)]
    public ?string $externalId = null;
}
