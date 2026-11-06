<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;

#[ScimResource('User')]
final class ElementAbstract
{
    use UserBasics;

    /** @var list<AbstractEmail> */
    #[ScimAttribute('emails', elementClass: AbstractEmail::class)]
    public array $emails = [];
}
