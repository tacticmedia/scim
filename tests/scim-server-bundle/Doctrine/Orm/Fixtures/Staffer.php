<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * "manager" on the inverse side of a OneToOne association: the manager row holds the foreign key.
 */
#[ORM\Entity]
#[ORM\Table(name: 'keyset_staffer')]
#[ScimResource('User', idPrefix: 'f')]
class Staffer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\OneToOne(targetEntity: self::class, inversedBy: 'manager')]
    public ?self $assistant = null;

    #[ORM\OneToOne(targetEntity: self::class, mappedBy: 'assistant')]
    #[ScimAttribute('manager')]
    public ?self $manager = null;

    public function __construct(
        #[ORM\Column(length: 20)]
        #[ScimAttribute('userName')]
        public string $userName,
    ) {
    }
}
