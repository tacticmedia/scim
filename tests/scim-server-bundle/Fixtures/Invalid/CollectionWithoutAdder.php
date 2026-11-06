<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('Group')]
final class CollectionWithoutAdder
{
    public string $id = '';

    #[ScimAttribute('displayName')]
    public string $displayName = '';

    /** @var list<object> */
    #[ScimAttribute('members[type eq "User"]')]
    public array $users = [];
}
