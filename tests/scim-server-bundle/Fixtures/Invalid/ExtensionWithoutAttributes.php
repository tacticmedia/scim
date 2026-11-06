<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', extensions: [new ScimExtension('urn:acme:scim:schemas:extension:acme:2.0:User', 'AcmeUser')])]
final class ExtensionWithoutAttributes
{
    use UserBasics;
}
