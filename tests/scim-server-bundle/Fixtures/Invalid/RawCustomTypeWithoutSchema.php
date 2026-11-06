<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;

#[AsResourceRepository('Printer', attributes: ['serialNumber'])]
final class RawCustomTypeWithoutSchema implements ResourceRepository
{
    use RepositoryBasics;
}
