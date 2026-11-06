<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', extensions: [new ScimExtension('urn:acme:scim:schemas:extension:acme:2.0:User', 'AcmeUser')])]
final class AppSimpleAndSubAttribute
{
    use UserBasics;

    #[ScimAttribute('urn:acme:scim:schemas:extension:acme:2.0:User:badge')]
    public ?string $badge = null;

    #[ScimAttribute('urn:acme:scim:schemas:extension:acme:2.0:User:badge.number')]
    public ?string $badgeNumber = null;
}
