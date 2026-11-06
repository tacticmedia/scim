<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class DefaultOnReadOnly
{
    use UserBasics;

    #[ScimAttribute('meta.created', default: '2026-01-01T00:00:00Z')]
    public ?\DateTimeImmutable $createdAt = null;
}
