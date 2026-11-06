<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Query\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;

final class PagerIm
{
    #[ScimAttribute('value')]
    public ?string $value = null;

    #[ScimAttribute('primary')]
    public ?bool $primary = null;
}
