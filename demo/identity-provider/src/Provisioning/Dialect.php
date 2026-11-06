<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

/**
 * The SCIM client whose requests the provisioning reproduces.
 *
 * @see https://learn.microsoft.com/en-us/entra/identity/app-provisioning/application-provisioning-config-problem-scim-compatibility
 * @see https://developer.okta.com/docs/api/openapi/okta-scim/guides/scim-20/
 * @see https://github.com/shebang-labs/scim-outbound-for-keycloak
 */
enum Dialect: string
{
    case EntraDefault = 'entra';
    case EntraFlag = 'entra-flag';
    case Okta = 'okta';
    case Keycloak = 'keycloak';

    public function label(): string
    {
        return match ($this) {
            self::EntraDefault => 'Microsoft Entra ID',
            self::EntraFlag => 'Microsoft Entra ID with the aadOptscim062020 flag',
            self::Okta => 'Okta (OIN integration)',
            self::Keycloak => 'Keycloak (scim-outbound-for-keycloak plugin)',
        };
    }

    public function operation(string $lowercaseOperation): string
    {
        return self::EntraDefault === $this ? ucfirst($lowercaseOperation) : $lowercaseOperation;
    }

    public function boolean(bool $value): bool|string
    {
        if (self::EntraDefault !== $this) {
            return $value;
        }

        return $value ? 'True' : 'False';
    }

    /**
     * @return array<string, string>
     */
    public function headers(string $token): array
    {
        return match ($this) {
            self::Okta => ['Authorization' => $token, 'User-Agent' => 'Okta SCIM Client 1.0.0'],
            default => ['Authorization' => 'Bearer '.$token],
        };
    }
}
