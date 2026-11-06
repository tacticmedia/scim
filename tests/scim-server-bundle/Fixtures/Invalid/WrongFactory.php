<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', factory: \stdClass::class)] // @phpstan-ignore argument.type (the loader rejects it)
final class WrongFactory
{
    use UserBasics;
}
