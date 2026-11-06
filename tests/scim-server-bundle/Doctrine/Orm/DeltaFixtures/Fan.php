<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Mapping\ScimSoftDelete;

/**
 * The readOnly "groups" is an owning ManyToMany with an adder and a remover. {@see Gang} has its own join table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'delta_fan')]
#[ScimResource('User', idPrefix: 'f')]
class Fan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[ScimAttribute('userName')]
    public string $userName;

    /** @var Collection<int, Gang> */
    #[ORM\ManyToMany(targetEntity: Gang::class)]
    #[ORM\JoinTable(name: 'delta_fan_gang')]
    #[ScimAttribute('groups')]
    private Collection $gangs;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    #[ScimAttribute('meta.version')]
    public int $version = 1;

    #[ORM\Column]
    #[ScimSoftDelete(revive: true)]
    public bool $deleted = false;

    public function __construct(string $userName)
    {
        $this->userName = $userName;
        $this->gangs = new ArrayCollection();
    }

    /**
     * @return Collection<int, Gang>
     */
    public function getGangs(): Collection
    {
        return $this->gangs;
    }

    public function addGang(Gang $gang): void
    {
        $this->gangs->add($gang);
    }

    public function removeGang(Gang $gang): void
    {
        $this->gangs->removeElement($gang);
    }
}
