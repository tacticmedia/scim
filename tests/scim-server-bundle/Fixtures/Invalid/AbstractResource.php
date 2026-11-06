<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
abstract class AbstractResource
{
    use UserBasics;
}
