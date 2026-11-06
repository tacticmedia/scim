<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RepositoryBasics;

/**
 * A raw repository of the RFC Group schema whose members are users only.
 */
#[AsResourceRepository('Group', attributes: ['displayName', 'members[type eq "User"]'], endpoint: '/Teams')]
final class DirectoryGroupRepository implements ResourceRepository
{
    use RepositoryBasics;
}
