<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', store: \stdClass::class)] // @phpstan-ignore argument.type (the loader rejects it)
final class WrongStore
{
    use UserBasics;
}
