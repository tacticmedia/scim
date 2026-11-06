<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;

#[ScimResource('User')]
final class ElementIsResource
{
    use UserBasics;

    /** @var list<Group> */
    #[ScimAttribute('emails', elementClass: Group::class)]
    public array $emails = [];
}
