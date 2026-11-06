<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\ArrayObjectStore;

/**
 * Team without meta.version.
 */
#[ScimResource('Group', store: ArrayObjectStore::class)]
final class Crew
{
    #[ScimAttribute('id')]
    public string $id = 'crew-1';

    /** @var list<Account> */
    #[ScimAttribute('members[type eq "User"]')]
    private array $members = [];

    public function __construct(
        #[ScimAttribute('displayName')]
        public string $displayName,
    ) {
    }

    /**
     * @return list<Account>
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    public function addMember(Account $account): void
    {
        $this->members[] = $account;
    }

    public function removeMember(Account $account): void
    {
        $this->members = array_values(array_filter($this->members, static fn (Account $member): bool => $member !== $account));
    }
}
