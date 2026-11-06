<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', extensions: [new ScimExtension('urn:ietf:params:scim:schemas:extension:enterprise:2.0:User', 'EnterpriseUser')])]
final class RfcExtensionDeclared
{
    use UserBasics;
}
