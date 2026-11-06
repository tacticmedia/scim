<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningSettingsRepository;

#[ORM\Entity(repositoryClass: ProvisioningSettingsRepository::class)]
final class ProvisioningSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    public function __construct(
        #[ORM\Column]
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: false)]
        public string $serviceProviderUrl,
        #[ORM\Column]
        #[Assert\NotBlank]
        public string $token,
        #[ORM\Column(enumType: Dialect::class)]
        public Dialect $dialect,
    ) {
    }
}
