<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\ServiceProvider\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use TacticMedia\ScimServerBundle\Converter\HashPassword;
use TacticMedia\ScimServerBundle\Doctrine\Orm\Mapping\ScimDqlExpression;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Mapping\ScimSoftDelete;

use function Symfony\Component\Clock\now;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ScimResource('User', validationGroups: ['scim'])]
final class User implements PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    #[ScimAttribute('externalId')]
    private ?string $externalId = null;

    #[ORM\Column(length: 180, unique: true)]
    #[ScimAttribute('userName')]
    #[Assert\NotBlank(normalizer: 'trim', groups: ['scim'])]
    private string $userName;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('name.givenName')]
    private ?string $givenName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('name.familyName')]
    private ?string $familyName = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[ScimAttribute('displayName')]
    private ?string $displayName = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[ScimAttribute('emails[type eq "work"].value', primary: true)]
    #[Assert\Email(groups: ['scim'])]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[ScimAttribute('phoneNumbers[type eq "mobile"].value')]
    private ?string $mobilePhone = null;

    #[ORM\Column]
    #[ScimAttribute('active', default: true)]
    private bool $active = true;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('title')]
    private ?string $title = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[ScimAttribute('employeeNumber')]
    private ?string $employeeNumber = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('department')]
    private ?string $department = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[ScimAttribute('manager')]
    private ?self $manager = null;

    #[ORM\Column(nullable: true)]
    #[ScimAttribute('password', converter: HashPassword::class)]
    private ?string $password = null;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class, mappedBy: 'users')]
    #[ScimAttribute('groups')]
    private Collection $groups;

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

    #[ORM\Column(nullable: true)]
    #[ScimSoftDelete]
    public private(set) ?\DateTimeImmutable $deletedAt = null;

    public function __construct(string $userName)
    {
        $this->id = Uuid::v7();
        $this->userName = $userName;
        $this->groups = new ArrayCollection();
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

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function setUserName(string $userName): void
    {
        $this->userName = $userName;
    }

    public function getGivenName(): ?string
    {
        return $this->givenName;
    }

    public function setGivenName(?string $givenName): void
    {
        $this->givenName = $givenName;
    }

    public function getFamilyName(): ?string
    {
        return $this->familyName;
    }

    public function setFamilyName(?string $familyName): void
    {
        $this->familyName = $familyName;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): void
    {
        $this->displayName = $displayName;
    }

    #[ScimAttribute('name.formatted')]
    #[ScimDqlExpression("TRIM(CONCAT(COALESCE({alias}.givenName, ''), ' ', COALESCE({alias}.familyName, '')))")]
    public function getFormattedName(): ?string
    {
        return trim($this->givenName.' '.$this->familyName) ?: null;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getMobilePhone(): ?string
    {
        return $this->mobilePhone;
    }

    public function setMobilePhone(?string $mobilePhone): void
    {
        $this->mobilePhone = $mobilePhone;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getEmployeeNumber(): ?string
    {
        return $this->employeeNumber;
    }

    public function setEmployeeNumber(?string $employeeNumber): void
    {
        $this->employeeNumber = $employeeNumber;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function setDepartment(?string $department): void
    {
        $this->department = $department;
    }

    public function getManager(): ?self
    {
        return $this->manager;
    }

    public function setManager(?self $manager): void
    {
        $this->manager = $manager;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): void
    {
        $this->password = $password;
    }

    /**
     * @return Collection<int, Group>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
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

    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
    }
}
