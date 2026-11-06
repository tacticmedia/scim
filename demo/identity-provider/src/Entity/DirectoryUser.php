<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[UniqueEntity('userName')]
final class DirectoryUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /** Unique regardless of case, as the Entra UPN and the Okta login. */
    #[ORM\Column(unique: true, options: ['collation' => 'NOCASE'])]
    #[Assert\NotBlank]
    public string $userName = '';

    #[ORM\Column]
    #[Assert\NotBlank]
    public string $givenName = '';

    #[ORM\Column]
    #[Assert\NotBlank]
    public string $familyName = '';

    #[ORM\Column]
    #[Assert\NotBlank]
    public string $displayName = '';

    #[ORM\Column(nullable: true)]
    #[Assert\Email]
    public ?string $workEmail = null;

    #[ORM\Column(nullable: true)]
    public ?string $mobilePhone = null;

    #[ORM\Column(nullable: true)]
    public ?string $department = null;

    #[ORM\Column(nullable: true)]
    public ?string $employeeNumber = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?DirectoryUser $manager = null;

    #[ORM\Column]
    public bool $active = true;

    #[ORM\Column(nullable: true)]
    public ?string $demoPlainTextPassword = null;

    #[ORM\Column(nullable: true)]
    public ?string $scimId = null;
}
