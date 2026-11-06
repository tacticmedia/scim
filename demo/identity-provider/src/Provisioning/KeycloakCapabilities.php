<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

/**
 * The plugin reads /ServiceProviderConfig to choose PATCH or PUT and keeps the answer for an hour. The IdP keeps it for
 * one HTTP request or command.
 */
final class KeycloakCapabilities
{
    private ?bool $patchSupported = null;

    public function __construct(
        private readonly ScimClient $scim,
    ) {
    }

    /**
     * False only when the service provider says so. A failed read assumes PATCH, as the plugin does.
     */
    public function patchSupported(): bool
    {
        return $this->patchSupported ??= $this->readPatchSupported();
    }

    private function readPatchSupported(): bool
    {
        try {
            $patch = Json::object($this->scim->send('GET', '/ServiceProviderConfig'), 'patch');
        } catch (ProvisioningFailed) {
            return true;
        }

        return false !== ($patch['supported'] ?? null);
    }
}
