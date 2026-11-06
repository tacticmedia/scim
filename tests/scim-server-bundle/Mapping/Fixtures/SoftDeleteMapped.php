<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Mapping\ScimSoftDelete;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;

#[ScimResource('User')]
final class SoftDeleteMapped
{
    use UserBasics;

    #[ScimAttribute('active', default: true)]
    #[ScimSoftDelete]
    public bool $active = true;
}
