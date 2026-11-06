<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * A group whose displayName need not be unique.
 */
#[ScimResource('Group')]
final class NamesakeGroup
{
    public int $id = 0;

    #[ScimAttribute('displayName', uniqueness: Uniqueness::None)]
    public string $displayName = '';
}
