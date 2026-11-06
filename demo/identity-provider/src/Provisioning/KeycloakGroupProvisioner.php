<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;

/**
 * Keycloak with syncGroups: a linked group gets a displayName PATCH on each sync, then the member additions and
 * removals as "members" value arrays of at most 100. Without PATCH, one PUT carries the name and every member. A 404
 * makes it look the group up by displayName and create it again.
 *
 * @see https://github.com/shebang-labs/scim-outbound-for-keycloak
 */
final readonly class KeycloakGroupProvisioner
{
    private const int MEMBER_CHUNK = 100;

    public function __construct(
        private ScimClient $scim,
        private ResourceLinker $linker,
        private KeycloakCapabilities $capabilities,
    ) {
    }

    public function sync(DirectoryGroup $group): void
    {
        $patch = $this->capabilities->patchSupported();

        if (null === $group->scimId || null === $this->update($group, $patch)) {
            $this->linkOrCreate($group, $patch);
        }

        if ($patch) {
            $this->changeMembers($group);
        }
    }

    /**
     * @return array<mixed>|null null when the service provider no longer has the group
     */
    private function update(DirectoryGroup $group, bool $patch): ?array
    {
        return $patch
            ? $this->scim->found('PATCH', $this->path($group), body: Scim::patch([['op' => 'replace', 'path' => 'displayName', 'value' => $group->displayName]]))
            : $this->put($group);
    }

    /**
     * An adopted group keeps its members until the member changes make them equal. A POST sends the first 100 members.
     */
    private function linkOrCreate(DirectoryGroup $group, bool $patch): void
    {
        $match = $this->scim->first('/Groups', ['filter' => Scim::equals('displayName', $group->displayName)]);

        if (null !== $match) {
            $this->linker->linkProvisioned($group, $match, KeycloakUserMapping::keycloakId($group));
            $group->provisionedMemberIds = $this->memberIds($match);

            if (!$patch) {
                $this->put($group);
            }

            return;
        }

        $memberIds = MembershipChange::of($group)->memberIds;
        $sentIds = $patch ? \array_slice($memberIds, 0, self::MEMBER_CHUNK) : $memberIds;
        $group->scimId = ScimClient::id($this->scim->send('POST', '/Groups', body: $this->body($group, $sentIds)));
        $group->provisionedMemberIds = $sentIds;
    }

    /**
     * @return array<mixed>|null null when the service provider no longer has the group
     */
    private function put(DirectoryGroup $group): ?array
    {
        $memberIds = MembershipChange::of($group)->memberIds;
        $replaced = $this->scim->found('PUT', $this->path($group), body: $this->body($group, $memberIds, $group->scimId));

        if (null !== $replaced) {
            $group->provisionedMemberIds = $memberIds;
        }

        return $replaced;
    }

    private function changeMembers(DirectoryGroup $group): void
    {
        $change = MembershipChange::of($group);

        foreach (['add' => $change->addedIds(), 'remove' => $change->removedIds] as $operation => $ids) {
            foreach (array_chunk($ids, self::MEMBER_CHUNK) as $chunk) {
                $this->scim->send('PATCH', $this->path($group), body: Scim::patch([['op' => $operation, 'path' => 'members', 'value' => $this->members($chunk)]]));
            }
        }

        $group->provisionedMemberIds = $change->memberIds;
    }

    /**
     * @param list<string> $memberIds
     *
     * @return array<string, mixed>
     */
    private function body(DirectoryGroup $group, array $memberIds, ?string $id = null): array
    {
        return array_filter([
            'schemas' => [Scim::GROUP],
            'id' => $id,
            'externalId' => KeycloakUserMapping::keycloakId($group),
            'displayName' => $group->displayName,
            'members' => $this->members($memberIds),
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array{value: string}>
     */
    private function members(array $ids): array
    {
        return array_map(static fn (string $id): array => ['value' => $id], $ids);
    }

    /**
     * @param array<mixed> $group
     *
     * @return list<string>
     */
    private function memberIds(array $group): array
    {
        return array_values(array_filter(array_map(static fn (mixed $member): ?string => \is_array($member) ? Json::string($member, 'value') : null, Json::object($group, 'members'))));
    }

    private function path(DirectoryGroup $group): string
    {
        return '/Groups/'.rawurlencode((string) $group->scimId);
    }
}
