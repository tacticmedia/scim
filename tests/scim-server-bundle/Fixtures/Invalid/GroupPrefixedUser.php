<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * The idPrefix of PrefixedGroup, so a member id "g1" is ambiguous.
 */
#[ScimResource('User', idPrefix: 'g')]
final class GroupPrefixedUser
{
    public int $id = 0;

    #[ScimAttribute('userName')]
    public string $userName = '';
}
