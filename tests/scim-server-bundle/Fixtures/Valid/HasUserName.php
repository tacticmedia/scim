<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;

trait HasUserName
{
    #[ScimAttribute('userName')]
    public string $userName = '';
}
