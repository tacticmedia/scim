<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;

#[ScimResource('User')]
final class ElementNotAClass
{
    use UserBasics;

    /** @var list<object> */
    #[ScimAttribute('emails', elementClass: 'NoSuchEmail')] // @phpstan-ignore argument.type
    public array $emails = [];
}
