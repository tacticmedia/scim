<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;

#[ScimResource('User')]
final class ElementOnMethod
{
    use UserBasics;

    /**
     * @return list<PlainEmail>
     */
    #[ScimAttribute('emails', elementClass: PlainEmail::class)]
    public function emails(): array
    {
        return [];
    }
}
