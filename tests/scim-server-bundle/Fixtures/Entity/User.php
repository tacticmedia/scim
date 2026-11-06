<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServerBundle\Converter\HashPassword;
use TacticMedia\ScimServerBundle\Doctrine\Orm\Mapping\ScimDqlExpression;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Mapping\ScimSoftDelete;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Enum\UserType;

use function Symfony\Component\Clock\now;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ScimResource('User')]
class User implements PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    #[ScimAttribute('externalId')]
    private ?string $externalId = null;

    #[ORM\Column(length: 180, unique: true)]
    #[ScimAttribute('userName')]
    private string $userName;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('name.givenName')]
    private ?string $firstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('name.familyName')]
    private ?string $lastName = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[ScimAttribute('displayName')]
    private ?string $displayName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('nickName')]
    private ?string $nickname = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('title')]
    private ?string $title = null;

    #[ORM\Column(nullable: true, enumType: UserType::class)]
    #[ScimAttribute('userType')]
    private ?UserType $type = null;

    #[ORM\Column]
    #[ScimAttribute('active', default: true)]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    #[ScimAttribute('password', converter: HashPassword::class)]
    private ?string $password = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[ScimAttribute('emails[type eq "work"].value', primary: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[ScimAttribute('phoneNumbers[type eq "work"].value', primary: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[ScimAttribute('phoneNumbers[type eq "mobile"].value')]
    private ?string $mobile = null;

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

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class, mappedBy: 'users')]
    #[ScimAttribute('groups')]
    private Collection $groups;

    /** @var Collection<int, UserAddress> */
    #[ORM\OneToMany(targetEntity: UserAddress::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[ScimAttribute('addresses', elementClass: UserAddress::class)]
    private Collection $addresses;

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
    #[ScimSoftDelete(revive: true)]
    public private(set) ?\DateTimeImmutable $deletedAt = null;

    public function __construct(string $userName)
    {
        $this->id = Uuid::v7();
        $this->userName = $userName;
        $this->groups = new ArrayCollection();
        $this->addresses = new ArrayCollection();
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

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): void
    {
        $this->lastName = $lastName;
    }

    #[ScimAttribute('name.formatted')]
    #[ScimDqlExpression("TRIM(CONCAT(COALESCE({alias}.firstName, ''), ' ', COALESCE({alias}.lastName, '')))")]
    public function getFormattedName(): ?string
    {
        return trim(($this->firstName ?? '').' '.($this->lastName ?? '')) ?: null;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): void
    {
        $this->nickname = $nickname;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getType(): ?UserType
    {
        return $this->type;
    }

    public function setType(?UserType $type): void
    {
        $this->type = $type;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): void
    {
        $this->password = $password;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function getMobile(): ?string
    {
        return $this->mobile;
    }

    public function setMobile(?string $mobile): void
    {
        $this->mobile = $mobile;
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

    /**
     * @return Collection<int, Group>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    /**
     * @return Collection<int, UserAddress>
     */
    public function getAddresses(): Collection
    {
        return $this->addresses;
    }

    public function addAddress(UserAddress $address): void
    {
        if (!$this->addresses->contains($address)) {
            $this->addresses->add($address);
        }
    }

    public function removeAddress(UserAddress $address): void
    {
        $this->addresses->removeElement($address);
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
