<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * Versioned, but maps no meta.version, so it takes the full PATCH path.
 */
#[ORM\Entity]
#[ORM\Table(name: 'delta_gang')]
#[ScimResource('Group', idPrefix: 'g')]
class Gang
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    #[ScimAttribute('displayName')]
    public string $displayName;

    /** @var Collection<int, Fan> */
    #[ORM\ManyToMany(targetEntity: Fan::class)]
    #[ORM\JoinTable(name: 'delta_gang_fan')]
    #[ScimAttribute('members[type eq "User"]')]
    private Collection $fans;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    public int $version = 1;

    public function __construct(string $displayName)
    {
        $this->displayName = $displayName;
        $this->fans = new ArrayCollection();
    }

    /**
     * @return Collection<int, Fan>
     */
    public function getFans(): Collection
    {
        return $this->fans;
    }

    public function addFan(Fan $fan): void
    {
        $this->fans->add($fan);
    }

    public function removeFan(Fan $fan): void
    {
        $this->fans->removeElement($fan);
    }
}
