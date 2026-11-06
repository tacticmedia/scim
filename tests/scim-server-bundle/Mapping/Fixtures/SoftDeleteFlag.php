<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Mapping\ScimSoftDelete;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;

#[ScimResource('User')]
final class SoftDeleteFlag
{
    use UserBasics;

    #[ScimSoftDelete]
    public bool $deleted = false;
}
