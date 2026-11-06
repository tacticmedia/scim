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
 * Qualifies for member deltas, but opts out.
 */
#[ORM\Entity]
#[ORM\Table(name: 'delta_band')]
#[ScimResource('Group', idPrefix: 'b', deltas: false)]
class Band
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    #[ScimAttribute('displayName')]
    public string $displayName;

    /** @var list<string> the user names that addPlayer() got */
    public array $joined = [];

    /** @var Collection<int, Player> */
    #[ORM\ManyToMany(targetEntity: Player::class)]
    #[ORM\JoinTable(name: 'delta_band_player')]
    #[ScimAttribute('members[type eq "User"]')]
    private Collection $players;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    #[ScimAttribute('meta.version')]
    public int $version = 1;

    public function __construct(string $displayName)
    {
        $this->displayName = $displayName;
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
        $this->joined[] = $player->userName;
    }

    public function removePlayer(Player $player): void
    {
        $this->players->removeElement($player);
    }
}
