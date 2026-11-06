<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\ServiceProvider\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

use function Symfony\Component\Clock\now;

#[ORM\Entity]
#[ORM\Table(name: 'app_group')]
#[ScimResource('Group')]
final class Group
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    #[ScimAttribute('externalId')]
    private ?string $externalId = null;

    #[ORM\Column(length: 255, unique: true)]
    #[ScimAttribute('displayName', uniqueness: Uniqueness::Server)]
    private string $displayName;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'groups')]
    #[ORM\JoinTable(name: 'app_group_user')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ScimAttribute('members[type eq "User"]')]
    private Collection $users;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'app_group_group')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'child_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ScimAttribute('members[type eq "Group"]')]
    private Collection $subgroups;

    #[ORM\Column]
    #[ScimAttribute('meta.created')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[ScimAttribute('meta.lastModified')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    #[ScimAttribute('meta.version')]
    private int $version = 1;

    public function __construct(string $displayName)
    {
        $this->id = Uuid::v7();
        $this->displayName = $displayName;
        $this->users = new ArrayCollection();
        $this->subgroups = new ArrayCollection();
        $this->createdAt = now();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): void
    {
        $this->externalId = $externalId;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): void
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
        }
    }

    public function removeUser(User $user): void
    {
        $this->users->removeElement($user);
    }

    /**
     * @return Collection<int, Group>
     */
    public function getSubgroups(): Collection
    {
        return $this->subgroups;
    }

    public function addSubgroup(self $group): void
    {
        if (!$this->subgroups->contains($group)) {
            $this->subgroups->add($group);
        }
    }

    public function removeSubgroup(self $group): void
    {
        $this->subgroups->removeElement($group);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
