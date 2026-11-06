<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * The difference between the provisioned members of a group and its members that the service provider knows.
 */
final readonly class MembershipChange
{
    /**
     * @param list<DirectoryUser> $added
     * @param list<string>        $removedIds
     * @param list<string>        $memberIds
     */
    private function __construct(
        public array $added,
        public array $removedIds,
        public array $memberIds,
    ) {
    }

    public static function of(DirectoryGroup $group): self
    {
        $provisionedMembers = $group->members->filter(static fn (DirectoryUser $member): bool => null !== $member->scimId)->getValues();
        $memberIds = array_map(static fn (DirectoryUser $member): string => (string) $member->scimId, $provisionedMembers);

        return new self(
            array_values(array_filter($provisionedMembers, static fn (DirectoryUser $member): bool => !\in_array($member->scimId, $group->provisionedMemberIds, true))),
            array_values(array_diff($group->provisionedMemberIds, $memberIds)),
            $memberIds,
        );
    }

    /**
     * @return list<string>
     */
    public function addedIds(): array
    {
        return array_map(static fn (DirectoryUser $member): string => (string) $member->scimId, $this->added);
    }

    public function isEmpty(): bool
    {
        return [] === $this->added && [] === $this->removedIds;
    }
}
