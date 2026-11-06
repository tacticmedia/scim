<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use TacticMedia\ScimServer\SchemaUrn;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * The RFC 7643 User schema under another resource type name, on the endpoint of IntUser.
 */
#[ScimResource('Person', endpoint: '/Users', schema: SchemaUrn::User->value)]
final class Person
{
    public string $id = '';

    #[ScimAttribute('userName')]
    public string $userName = '';
}
