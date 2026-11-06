<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use TacticMedia\ScimServerBundle\Tests\Fixtures\TestKernel;

final class Scim
{
    public const string BASE_URL = 'http://localhost/scim/v2';
    public const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    public const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    public const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    public const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    public const string LIST_RESPONSE = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    public const array ENTRA = ['Authorization' => 'Bearer '.TestKernel::ENTRA_TOKEN];
    public const array OKTA = ['User-Agent' => 'Okta SCIM Client 1.0.0', 'Authorization' => TestKernel::OKTA_TOKEN];
}
