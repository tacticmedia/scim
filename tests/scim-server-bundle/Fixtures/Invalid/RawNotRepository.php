<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;

#[AsResourceRepository('User', attributes: ['userName'])]
final class RawNotRepository
{
}
