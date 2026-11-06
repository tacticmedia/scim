<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;

#[AsResourceRepository('Printer', attributes: ['serialNumber'], schema: 'urn:acme:scim:schemas:core:1.0:Printer')]
final class RawUnknownSchema implements ResourceRepository
{
    use RepositoryBasics;
}
