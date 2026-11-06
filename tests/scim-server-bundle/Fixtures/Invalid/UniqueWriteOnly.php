<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', extensions: [new ScimExtension('urn:acme:scim:schemas:extension:acme:2.0:User', 'AcmeUser')])]
final class UniqueWriteOnly
{
    use UserBasics;

    #[ScimAttribute('urn:acme:scim:schemas:extension:acme:2.0:User:pin', mutability: Mutability::WriteOnly, uniqueness: Uniqueness::Server)]
    public ?string $pin = null;
}
