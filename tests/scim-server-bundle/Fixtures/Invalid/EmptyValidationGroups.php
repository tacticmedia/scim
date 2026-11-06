<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', validationGroups: [])]
final class EmptyValidationGroups
{
    public string $id = '';

    #[ScimAttribute('userName')]
    public string $userName = '';
}
