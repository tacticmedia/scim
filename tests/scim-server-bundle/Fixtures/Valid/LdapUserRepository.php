<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RepositoryBasics;

/**
 * A raw repository of the RFC User schema with the enterprise extension.
 */
#[AsResourceRepository('User', attributes: ['userName', 'name.givenName', 'emails[type eq "work"].value', 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:department', 'groups', 'id'])]
final class LdapUserRepository implements ResourceRepository
{
    use RepositoryBasics;
}
