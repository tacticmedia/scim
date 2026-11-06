<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;

#[AsResourceRepository('User', attributes: ['nickNam'])]
final class RawUnknownAttribute implements ResourceRepository
{
    use RepositoryBasics;
}
