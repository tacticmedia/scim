<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Validation\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * Constraints in the group "scim", and NotBlank in "Default", which the mapping does not validate.
 */
#[ORM\Entity]
#[ORM\Table(name: 'validation_member')]
#[ScimResource('User', idPrefix: 'm', validationGroups: ['scim'])]
#[UniqueEntity(fields: ['email'], groups: ['scim'])]
class Member
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    #[ScimAttribute('userName')]
    #[Assert\Length(min: 3, groups: ['scim'])]
    public string $userName;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('emails[type eq "work"].value')]
    #[Assert\Email(groups: ['scim'])]
    public ?string $email = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[ScimAttribute('displayName')]
    #[Assert\NotBlank]
    public ?string $displayName = null;

    public function __construct(string $userName)
    {
        $this->userName = $userName;
    }

    #[Assert\Callback(groups: ['scim'])]
    public function rejectReservedName(ExecutionContextInterface $context): void
    {
        if ('root' === $this->userName) {
            $context->addViolation('The user name root is reserved.');
        }
    }
}
