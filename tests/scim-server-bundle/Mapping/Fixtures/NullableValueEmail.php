<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;

/**
 * "value" is optional, but the constructor parameter $value does not accept null.
 */
final class NullableValueEmail
{
    #[ScimAttribute('value')]
    public ?string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }
}
