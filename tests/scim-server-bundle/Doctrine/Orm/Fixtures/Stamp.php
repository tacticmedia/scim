<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * A datetime #[ORM\Version] field without a meta.version mapping.
 */
#[ORM\Entity]
#[ORM\Table(name: 'keyset_stamp')]
#[ScimResource('Stamp', schema: 'urn:acme:scim:schemas:core:1.0:Stamp', idPrefix: 'p')]
class Stamp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Version]
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    public ?\DateTime $updatedAt = null;

    public function __construct(
        #[ORM\Column(length: 20)]
        #[ScimAttribute('name', required: true)]
        public string $name,
    ) {
    }
}
