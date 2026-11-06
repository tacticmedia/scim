<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * A string id in a guid column, a native uuid column on PostgreSQL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'keyset_locker')]
#[ScimResource('Locker', schema: 'urn:acme:scim:schemas:core:1.0:Locker')]
class Locker
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    public string $id;

    public function __construct(
        #[ORM\Column(length: 20)]
        #[ScimAttribute('label', required: true)]
        public string $label,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
    }
}
