<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;

#[ScimResource('User')]
final class ElementWithoutMembers
{
    use UserBasics;

    /** @var list<\stdClass> */
    #[ScimAttribute('emails', elementClass: \stdClass::class)]
    public array $emails = [];
}
