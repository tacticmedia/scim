<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * Per user: read the linked resource, or query each matching attribute and adopt a match, or create the user; then
 * PATCH the changed attributes, then the manager reference.
 *
 * @see https://learn.microsoft.com/en-us/entra/identity/app-provisioning/how-provisioning-works
 */
final readonly class EntraUserProvisioner
{
    public function __construct(
        private ScimClient $scim,
        private ResourceLinker $linker,
    ) {
    }

    public function sync(DirectoryUser $user, Dialect $dialect): void
    {
        $current = $this->scim->get('/Users', $user->scimId) ?? $this->matched($user) ?? $this->created($user);
        $changes = EntraUserMapping::changes($user, $current);

        if ([] !== $changes) {
            $this->scim->send('PATCH', $this->path($user), body: Scim::patch(EntraOperations::attributes($changes, $dialect)));
        }

        $managerOperation = EntraUserMapping::managerOperation($user, $current, $dialect);

        if (null !== $managerOperation) {
            $this->scim->send('PATCH', $this->path($user), body: Scim::patch([$managerOperation]));
        }
    }

    public function delete(string $scimId): void
    {
        $this->scim->deprovision('DELETE', '/Users/'.rawurlencode($scimId));
    }

    /**
     * @return array<mixed>|null
     */
    private function matched(DirectoryUser $user): ?array
    {
        foreach (['userName' => $user->userName, 'externalId' => EntraUserMapping::externalId($user)] as $attribute => $value) {
            $match = $this->scim->first('/Users', ['filter' => Scim::equals($attribute, $value)]);

            if (null !== $match) {
                $this->linker->link($user, $match);

                return $match;
            }
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private function created(DirectoryUser $user): array
    {
        $created = $this->scim->send('POST', '/Users', body: EntraUserMapping::createBody($user));
        $user->scimId = ScimClient::id($created);

        return $created;
    }

    private function path(DirectoryUser $user): string
    {
        return '/Users/'.rawurlencode((string) $user->scimId);
    }
}
