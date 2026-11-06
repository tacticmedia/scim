<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('Group', extensions: [new ScimExtension('urn:acme:scim:schemas:extension:tenant:1.0', 'Tenant')])]
final class TenantGroup
{
    public string $id = '';

    #[ScimAttribute('displayName')]
    public string $displayName = '';

    #[ScimAttribute('urn:acme:scim:schemas:extension:tenant:1.0:tenantId')]
    public ?int $tenantId = null;
}
