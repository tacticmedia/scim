<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;

/**
 * Entra reads groups without members, creates a group without members and then PATCHes the members that it
 * provisioned.
 */
final readonly class EntraGroupProvisioner
{
    private const array WITHOUT_MEMBERS = ['excludedAttributes' => 'members'];

    public function __construct(
        private ScimClient $scim,
        private ResourceLinker $linker,
    ) {
    }

    public function sync(DirectoryGroup $group, Dialect $dialect): void
    {
        $current = $this->scim->get('/Groups', $group->scimId, self::WITHOUT_MEMBERS) ?? $this->linked($group, $this->matched($group) ?? $this->created($group));

        if (Json::string($current, 'displayName') !== $group->displayName) {
            $this->scim->send('PATCH', $this->path($group), body: Scim::patch([['op' => $dialect->operation('replace'), 'path' => 'displayName', 'value' => $group->displayName]]));
        }

        $change = MembershipChange::of($group);

        if (!$change->isEmpty()) {
            $this->scim->send('PATCH', $this->path($group), body: Scim::patch(EntraOperations::members($change->addedIds(), $change->removedIds, $dialect)));
            $group->provisionedMemberIds = $change->memberIds;
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function matched(DirectoryGroup $group): ?array
    {
        return $this->scim->first('/Groups', [...self::WITHOUT_MEMBERS, 'filter' => Scim::equals('displayName', $group->displayName)]);
    }

    /**
     * @return array<mixed>
     */
    private function created(DirectoryGroup $group): array
    {
        return $this->scim->send('POST', '/Groups', body: [
            'schemas' => [Scim::GROUP, 'http://schemas.microsoft.com/2006/11/ResourceManagement/ADSCIM/2.0/Group'],
            'externalId' => (string) $group->id,
            'displayName' => $group->displayName,
            'meta' => ['resourceType' => 'Group'],
        ]);
    }

    /**
     * @param array<mixed> $resource
     *
     * @return array<mixed>
     */
    private function linked(DirectoryGroup $group, array $resource): array
    {
        $this->linker->link($group, $resource);
        $group->provisionedMemberIds = [];

        return $resource;
    }

    private function path(DirectoryGroup $group): string
    {
        return '/Groups/'.rawurlencode((string) $group->scimId);
    }
}
