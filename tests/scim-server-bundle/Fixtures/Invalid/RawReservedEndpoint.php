<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;

#[AsResourceRepository('User', attributes: ['userName'], endpoint: '/Schemas')]
final class RawReservedEndpoint implements ResourceRepository
{
    use RepositoryBasics;
}
