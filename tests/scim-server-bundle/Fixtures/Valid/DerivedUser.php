<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * The implicit id is a private property of the parent class, userName comes from a trait, and meta.created has a
 * private setter.
 */
#[ScimResource('User')]
final class DerivedUser extends BaseEntity
{
    use HasUserName;

    #[ScimAttribute('meta.created')]
    public private(set) ?\DateTimeImmutable $createdAt = null;
}
