<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TacticMedia\ScimDemo\IdentityProvider\Entity\ProvisioningSettings;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;

/**
 * @extends ServiceEntityRepository<ProvisioningSettings>
 */
final class ProvisioningSettingsRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        #[Autowire(env: 'SCIM_BASE_URL')]
        private readonly string $defaultServiceProviderUrl,
        #[Autowire(env: 'SCIM_TOKEN')]
        private readonly string $defaultToken,
        #[Autowire(env: 'enum:'.Dialect::class.':SCIM_DIALECT')]
        private readonly Dialect $defaultDialect,
    ) {
        parent::__construct($registry, ProvisioningSettings::class);
    }

    /**
     * The stored row, or new unsaved settings from the environment variables.
     */
    public function current(): ProvisioningSettings
    {
        return $this->findOneBy([]) ?? new ProvisioningSettings($this->defaultServiceProviderUrl, $this->defaultToken, $this->defaultDialect);
    }
}
