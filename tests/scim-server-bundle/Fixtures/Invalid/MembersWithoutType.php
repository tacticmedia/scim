<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('Group')]
final class MembersWithoutType
{
    public string $id = '';

    #[ScimAttribute('displayName')]
    public string $displayName = '';

    /** @var list<object> */
    #[ScimAttribute('members')]
    public array $members = [];
}
