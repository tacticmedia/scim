<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Validation\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * Qualifies for member deltas: at most two members.
 */
#[ORM\Entity]
#[ORM\Table(name: 'validation_roster')]
#[ScimResource('Group', idPrefix: 'r', validationGroups: ['scim'])]
class Roster
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    #[ScimAttribute('displayName')]
    #[Assert\Length(max: 20, groups: ['scim'])]
    public string $displayName;

    /** @var Collection<int, Member> */
    #[ORM\ManyToMany(targetEntity: Member::class)]
    #[ORM\JoinTable(name: 'validation_roster_member')]
    #[ScimAttribute('members[type eq "User"]')]
    #[Assert\Count(max: 2, groups: ['scim'])]
    private Collection $members;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    #[ScimAttribute('meta.version')]
    public int $version = 1;

    public function __construct(string $displayName)
    {
        $this->displayName = $displayName;
        $this->members = new ArrayCollection();
    }

    /**
     * @return Collection<int, Member>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(Member $member): void
    {
        $this->members->add($member);
    }

    public function removeMember(Member $member): void
    {
        $this->members->removeElement($member);
    }
}
