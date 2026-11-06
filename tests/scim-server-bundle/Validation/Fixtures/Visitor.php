<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Validation\Fixtures;

use Symfony\Component\Validator\Constraints as Assert;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * A constraint, but no validationGroups.
 */
#[ScimResource('User', idPrefix: 'v')]
final class Visitor
{
    public int $id = 0;

    #[ScimAttribute('userName')]
    #[Assert\Length(min: 3)]
    public string $userName = 'x';
}
