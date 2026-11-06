<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * Nullable members of other types than string, for keyset pages. Mapped by Doctrine only in the environment keyset.
 */
#[ORM\Entity]
#[ORM\Table(name: 'keyset_shift')]
#[ScimResource('Shift', schema: 'urn:acme:scim:schemas:core:1.0:Shift', idPrefix: 's')]
class Shift
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(nullable: true)]
    #[ScimAttribute('endsAt')]
    public ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(nullable: true)]
    #[ScimAttribute('floor')]
    public ?int $floor = null;

    #[ORM\Column(nullable: true)]
    #[ScimAttribute('rate')]
    public ?float $rate = null;

    #[ORM\Column(nullable: true)]
    #[ScimAttribute('active')]
    public ?bool $active = null;

    #[ORM\Column(type: Types::BINARY, length: 16, nullable: true)]
    #[ScimAttribute('code')]
    public ?string $code = null;

    public function __construct(
        #[ORM\Column(length: 20)]
        #[ScimAttribute('name', required: true, caseExact: true)]
        public string $name,
    ) {
    }
}
