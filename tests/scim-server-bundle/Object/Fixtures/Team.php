<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\ArrayObjectStore;

/**
 * A group with meta.version, so the repository may leave out relations. Counts the reads of $members.
 */
#[ScimResource('Group', store: ArrayObjectStore::class)]
final class Team
{
    #[ScimAttribute('id')]
    public string $id = 'team-1';

    #[ScimAttribute('meta.version')]
    public string $version = 'W/"7"';

    public int $memberReads = 0;

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
        ++$this->memberReads;

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
