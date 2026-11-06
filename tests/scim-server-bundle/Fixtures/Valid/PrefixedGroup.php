<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * Two reference collections whose targets have int ids, with idPrefix "g" here.
 */
#[ScimResource('Group', idPrefix: 'g')]
final class PrefixedGroup
{
    public int $id = 0;

    #[ScimAttribute('displayName')]
    public string $displayName = '';

    /** @var list<object> */
    #[ScimAttribute('members[type eq "User"]')]
    public array $users = [];

    /** @var list<PrefixedGroup> */
    #[ScimAttribute('members[type eq "Group"]')]
    public array $groups = [];

    public function addUser(object $user): void
    {
        $this->users[] = $user;
    }

    public function removeUser(object $user): void
    {
        $this->users = array_values(array_filter($this->users, static fn (object $member): bool => $member !== $user));
    }

    public function addGroup(self $group): void
    {
        $this->groups[] = $group;
    }

    public function removeGroup(self $group): void
    {
        $this->groups = array_values(array_filter($this->groups, static fn (self $member): bool => $member !== $group));
    }
}
