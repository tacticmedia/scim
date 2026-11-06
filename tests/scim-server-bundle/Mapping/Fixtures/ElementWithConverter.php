<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\TagConverter;

#[ScimResource('User')]
final class ElementWithConverter
{
    use UserBasics;

    /** @var list<PlainEmail> */
    #[ScimAttribute('emails', converter: TagConverter::class, elementClass: PlainEmail::class)]
    public array $emails = [];
}
