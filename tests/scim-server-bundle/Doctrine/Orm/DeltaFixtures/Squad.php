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
 * Int ids with idPrefix: "s" for squads, "p" for players.
 */
#[ORM\Entity]
#[ORM\Table(name: 'delta_squad')]
#[ScimResource('Group', idPrefix: 's')]
class Squad
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    #[ScimAttribute('displayName')]
    public string $displayName;

    /** @var Collection<int, Player> */
    #[ORM\ManyToMany(targetEntity: Player::class)]
    #[ORM\JoinTable(name: 'delta_squad_player')]
    #[ScimAttribute('members[type eq "User"]')]
    private Collection $players;

    /** @var Collection<int, Squad> */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'delta_squad_squad')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'child_id', referencedColumnName: 'id')]
    #[ScimAttribute('members[type eq "Group"]')]
    private Collection $squads;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    #[ScimAttribute('meta.version')]
    public int $version = 1;

    public function __construct(string $displayName)
    {
        $this->displayName = $displayName;
        $this->players = new ArrayCollection();
        $this->squads = new ArrayCollection();
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

    /**
     * @return Collection<int, Squad>
     */
    public function getSquads(): Collection
    {
        return $this->squads;
    }

    public function addSquad(self $squad): void
    {
        $this->squads->add($squad);
    }

    public function removeSquad(self $squad): void
    {
        $this->squads->removeElement($squad);
    }
}
