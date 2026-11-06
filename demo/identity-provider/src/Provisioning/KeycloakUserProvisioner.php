<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * Keycloak never reads a linked user: it PATCHes every mapped attribute, or PUTs the user when the service provider
 * does not support PATCH. A 404 makes it look the user up by userName and create it again. A deleted user is
 * deactivated, the plugin default.
 *
 * @see https://github.com/shebang-labs/scim-outbound-for-keycloak
 */
final readonly class KeycloakUserProvisioner
{
    public function __construct(
        private ScimClient $scim,
        private ResourceLinker $linker,
        private KeycloakCapabilities $capabilities,
    ) {
    }

    public function sync(DirectoryUser $user): void
    {
        $patch = $this->capabilities->patchSupported();

        if (null !== $user->scimId && null !== $this->update($user, $patch)) {
            return;
        }

        $match = $this->scim->first('/Users', ['filter' => Scim::equals('userName', $user->userName)]);

        if (null === $match) {
            $user->scimId = ScimClient::id($this->scim->send('POST', '/Users', body: KeycloakUserMapping::user($user)));

            return;
        }

        $this->linker->linkProvisioned($user, $match, KeycloakUserMapping::keycloakId($user));
        $this->update($user, $patch);
    }

    /**
     * Without PATCH, the plugin reads the user and PUTs it with "active" false.
     */
    public function deactivate(string $scimId): void
    {
        $path = '/Users/'.rawurlencode($scimId);

        if ($this->capabilities->patchSupported()) {
            $this->scim->deprovision('PATCH', $path, Scim::patch([['op' => 'replace', 'path' => 'active', 'value' => false]]));

            return;
        }

        $current = $this->scim->get('/Users', $scimId);

        if (null !== $current) {
            $this->scim->deprovision('PUT', $path, KeycloakUserMapping::inactive($current));
        }
    }

    /**
     * @return array<mixed>|null null when the service provider no longer has the user
     */
    private function update(DirectoryUser $user, bool $patch): ?array
    {
        $path = '/Users/'.rawurlencode((string) $user->scimId);

        return $patch
            ? $this->scim->found('PATCH', $path, body: KeycloakUserMapping::replacePatch($user))
            : $this->scim->found('PUT', $path, body: KeycloakUserMapping::user($user, $user->scimId));
    }
}
