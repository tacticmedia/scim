<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;

/**
 * An element of the element collection User "addresses". The constructor gets the owner by type and "type" by name.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user_address')]
class UserAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[ScimAttribute('streetAddress')]
    private ?string $street = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('locality')]
    private ?string $locality = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[ScimAttribute('postalCode')]
    private ?string $postalCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('country')]
    private ?string $country = null;

    #[ORM\Column(name: 'is_primary')]
    #[ScimAttribute('primary', default: false)]
    private bool $primary = false;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'addresses')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 20, nullable: true)]
        #[ScimAttribute('type')]
        private ?string $type = null,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): void
    {
        $this->street = $street;
    }

    public function getLocality(): ?string
    {
        return $this->locality;
    }

    public function setLocality(?string $locality): void
    {
        $this->locality = $locality;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): void
    {
        $this->postalCode = $postalCode;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): void
    {
        $this->country = $country;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function setPrimary(bool $primary): void
    {
        $this->primary = $primary;
    }
}
