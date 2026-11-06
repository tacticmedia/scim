<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;

/**
 * An element of Crew "emails", with a random UUID v4 id unless the constructor gets one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'keyset_email')]
class CrewEmail
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    public function __construct(
        #[ORM\Column(length: 50)]
        #[ScimAttribute('value')]
        public string $value,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v4()->toRfc4122();
    }
}
