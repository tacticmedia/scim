<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures;

use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Mapping\ScimSoftDelete;

/**
 * The partial unique index of the guide. Doctrine DBAL creates its WHERE only on PostgreSQL, which spells it in
 * parentheses.
 */
#[ORM\Entity]
#[ORM\Table(name: 'delta_rookie')]
#[ORM\UniqueConstraint(name: 'delta_rookie_live_user_name', columns: ['user_name'], options: ['where' => '(deleted_at IS NULL)'])]
#[ScimResource('User', idPrefix: 'r')]
class Rookie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(name: 'user_name', length: 100)]
    #[ScimAttribute('userName')]
    public string $userName;

    #[ORM\Column(name: 'deleted_at', nullable: true)]
    #[ScimSoftDelete]
    public ?\DateTimeImmutable $deletedAt = null;

    public function __construct(string $userName)
    {
        $this->userName = $userName;
    }
}
