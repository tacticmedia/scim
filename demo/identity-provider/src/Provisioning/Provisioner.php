<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use Doctrine\ORM\EntityManagerInterface;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningSettingsRepository;

/**
 * Provisions in the dialect of the current settings. Each call flushes the SCIM ids and the log entries, also when
 * it fails.
 */
final readonly class Provisioner
{
    public function __construct(
        private ProvisioningSettingsRepository $settingsRepository,
        private EntraUserProvisioner $entraUsers,
        private OktaUserProvisioner $oktaUsers,
        private EntraGroupProvisioner $entraGroups,
        private OktaGroupProvisioner $oktaGroups,
        private KeycloakUserProvisioner $keycloakUsers,
        private KeycloakGroupProvisioner $keycloakGroups,
        private ScimClient $scim,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function syncUser(DirectoryUser $user): void
    {
        $dialect = $this->settingsRepository->current()->dialect;
        $this->flushAfter(function () use ($user, $dialect): void {
            if (Dialect::Okta === $dialect) {
                $this->oktaUsers->sync($user);
            } elseif (Dialect::Keycloak === $dialect) {
                $this->keycloakUsers->sync($user);
            } else {
                $this->entraUsers->sync($user, $dialect);
            }
        });
    }

    public function deleteUser(string $scimId): void
    {
        $dialect = $this->settingsRepository->current()->dialect;
        $this->flushAfter(function () use ($scimId, $dialect): void {
            if (Dialect::Okta === $dialect) {
                $this->oktaUsers->deactivate($scimId);
            } elseif (Dialect::Keycloak === $dialect) {
                $this->keycloakUsers->deactivate($scimId);
            } else {
                $this->entraUsers->delete($scimId);
            }
        });
    }

    public function syncGroup(DirectoryGroup $group): void
    {
        $dialect = $this->settingsRepository->current()->dialect;
        $this->flushAfter(function () use ($group, $dialect): void {
            if (Dialect::Okta === $dialect) {
                $this->oktaGroups->sync($group);
            } elseif (Dialect::Keycloak === $dialect) {
                $this->keycloakGroups->sync($group);
            } else {
                $this->entraGroups->sync($group, $dialect);
            }
        });
    }

    public function deleteGroup(string $scimId): void
    {
        $this->flushAfter(function () use ($scimId): void {
            $this->scim->deprovision('DELETE', '/Groups/'.rawurlencode($scimId));
        });
    }

    /**
     * Every user, then every group, as a full cycle does. A failure does not stop the cycle.
     *
     * @return list<string> the failure messages
     */
    public function syncAll(): array
    {
        $failures = [];

        foreach ($this->entityManager->getRepository(DirectoryUser::class)->findBy([], ['id' => 'ASC']) as $user) {
            $failures[] = $this->failure(fn () => $this->syncUser($user));
        }

        foreach ($this->entityManager->getRepository(DirectoryGroup::class)->findBy([], ['id' => 'ASC']) as $group) {
            $failures[] = $this->failure(fn () => $this->syncGroup($group));
        }

        return array_values(array_filter($failures));
    }

    private function flushAfter(\Closure $provisioning): void
    {
        try {
            $provisioning();
        } finally {
            $this->entityManager->flush();
        }
    }

    private function failure(\Closure $provisioning): ?string
    {
        try {
            $provisioning();

            return null;
        } catch (ProvisioningFailed $provisioningFailed) {
            return $provisioningFailed->getMessage();
        }
    }
}
