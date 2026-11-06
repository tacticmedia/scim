<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * Shares the tenant extension with TenantGroup, which maps tenantId with another type.
 */
#[ScimResource('User', extensions: [new ScimExtension('urn:acme:scim:schemas:extension:tenant:1.0', 'Tenant')])]
final class TenantUser
{
    use UserBasics;

    #[ScimAttribute('urn:acme:scim:schemas:extension:tenant:1.0:tenantId')]
    public ?string $tenantId = null;
}
