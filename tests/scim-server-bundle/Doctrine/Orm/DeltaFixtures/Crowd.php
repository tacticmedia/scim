<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * A ManyToMany owning side on a class without #[ORM\Version].
 */
#[ORM\Entity]
#[ORM\Table(name: 'delta_crowd')]
#[ScimResource('Group', idPrefix: 'c')]
class Crowd
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    #[ScimAttribute('displayName')]
    public string $displayName = 'Crowd';

    /** @var Collection<int, Player> */
    #[ORM\ManyToMany(targetEntity: Player::class)]
    #[ORM\JoinTable(name: 'delta_crowd_player')]
    #[ScimAttribute('members[type eq "User"]')]
    private Collection $players;

    public function __construct()
    {
        $this->players = new ArrayCollection();
    }

    /**
     * @return Collection<int, Player>
     */
    public function getPlayers(): Collection
    {
        return $this->players;
    }

    public function addPlayer(Player $player): void
    {
        $this->players->add($player);
    }

    public function removePlayer(Player $player): void
    {
        $this->players->removeElement($player);
    }
}
