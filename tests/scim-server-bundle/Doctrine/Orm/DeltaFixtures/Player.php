<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures;

use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Mapping\ScimSoftDelete;

#[ORM\Entity]
#[ORM\Table(name: 'delta_player')]
#[ScimResource('User', idPrefix: 'p')]
class Player
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[ScimAttribute('userName')]
    public string $userName;

    #[ORM\Column]
    #[ScimSoftDelete]
    public bool $deleted = false;

    public function __construct(string $userName)
    {
        $this->userName = $userName;
    }
}
