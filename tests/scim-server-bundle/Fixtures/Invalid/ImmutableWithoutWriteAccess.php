<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', extensions: [new ScimExtension('urn:acme:scim:schemas:extension:acme:2.0:User', 'AcmeUser')])]
final class ImmutableWithoutWriteAccess
{
    use UserBasics;

    #[ScimAttribute('urn:acme:scim:schemas:extension:acme:2.0:User:badge', mutability: Mutability::Immutable)]
    public readonly ?string $badge;

    public function __construct()
    {
        $this->badge = null;
    }
}
