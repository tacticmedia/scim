<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[UniqueEntity('displayName')]
final class DirectoryGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(unique: true)]
    #[Assert\NotBlank]
    public string $displayName = '';

    /** @var Collection<int, DirectoryUser> */
    #[ORM\ManyToMany(targetEntity: DirectoryUser::class)]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $members;

    #[ORM\Column(nullable: true)]
    public ?string $scimId = null;

    /**
     * The members at the service provider after the last successful membership update, as the IdP tracks them.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    public array $provisionedMemberIds = [];

    public function __construct()
    {
        $this->members = new ArrayCollection();
    }
}
