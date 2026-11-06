<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;

/**
 * The id and the required userName, so that a fixture shows only its own error.
 */
trait UserBasics
{
    public string $id = '';

    #[ScimAttribute('userName')]
    public string $userName = '';
}
