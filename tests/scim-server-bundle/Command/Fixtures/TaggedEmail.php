<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Command\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Tag;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\TagConverter;

final class TaggedEmail
{
    #[ScimAttribute('value', converter: TagConverter::class)]
    public ?Tag $value = null;
}
