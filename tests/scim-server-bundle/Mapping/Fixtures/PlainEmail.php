<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;

final class PlainEmail
{
    #[ScimAttribute('value')]
    public ?string $value = null;
}
