<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * Group Push: Okta never reads a linked group. It sends a rename PATCH with the group id in the value on each push
 * except the creation of an empty group, then the member removals and additions.
 */
final readonly class OktaGroupProvisioner
{
    public function __construct(
        private ScimClient $scim,
        private ResourceLinker $linker,
    ) {
    }

    public function sync(DirectoryGroup $group): void
    {
        $created = false;

        if (null === $group->scimId) {
            $created = $this->link($group);
        }

        $change = MembershipChange::of($group);

        if (!$created || !$change->isEmpty()) {
            $this->scim->send('PATCH', $this->path($group), body: Scim::patch([['op' => 'replace', 'value' => ['id' => $group->scimId, 'displayName' => $group->displayName]]]));
        }

        if (!$change->isEmpty()) {
            $this->scim->send('PATCH', $this->path($group), body: Scim::patch($this->memberOperations($change)));
            $group->provisionedMemberIds = $change->memberIds;
        }
    }

    /**
     * @return bool whether the group was created
     */
    private function link(DirectoryGroup $group): bool
    {
        $match = $this->scim->first('/Groups', ['filter' => Scim::equals('displayName', $group->displayName), ...OktaUserProvisioner::PAGE]);
        $resource = $match ?? $this->scim->send('POST', '/Groups', body: ['schemas' => [Scim::GROUP], 'displayName' => $group->displayName, 'members' => []]);
        $this->linker->link($group, $resource);
        $group->provisionedMemberIds = [];

        return null === $match;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function memberOperations(MembershipChange $change): array
    {
        $operations = array_map(static fn (string $id): array => ['op' => 'remove', 'path' => \sprintf('members[value eq "%s"]', $id)], $change->removedIds);

        if ([] !== $change->added) {
            $operations[] = ['op' => 'add', 'path' => 'members', 'value' => array_map(static fn (DirectoryUser $member): array => ['value' => (string) $member->scimId, 'display' => $member->userName], $change->added)];
        }

        return $operations;
    }

    private function path(DirectoryGroup $group): string
    {
        return '/Groups/'.rawurlencode((string) $group->scimId);
    }
}
