<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * An int id with idPrefix "b".
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_badge')]
#[ScimResource('Badge', schema: 'urn:acme:scim:schemas:core:1.0:Badge', idPrefix: 'b')]
class Badge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[ScimAttribute('serialNumber', required: true, caseExact: true, uniqueness: Uniqueness::Server)]
    private string $serialNumber;

    public function __construct(string $serialNumber)
    {
        $this->serialNumber = $serialNumber;
    }

    public function getSerialNumber(): string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(string $serialNumber): void
    {
        $this->serialNumber = $serialNumber;
    }
}
