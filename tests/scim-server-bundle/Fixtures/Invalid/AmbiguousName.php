<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', extensions: [new ScimExtension('urn:acme:scim:schemas:extension:acme:2.0:User', 'AcmeUser')])]
final class AmbiguousName
{
    use UserBasics;

    #[ScimAttribute('urn:acme:scim:schemas:extension:acme:2.0:User:costCenter')]
    public ?string $acmeCostCenter = null;

    #[ScimAttribute('costCenter')]
    public ?string $costCenter = null;
}
