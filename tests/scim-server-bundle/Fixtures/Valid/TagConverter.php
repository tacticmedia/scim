<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;

final readonly class TagConverter implements ValueConverter
{
    public function toScim(mixed $value, ConversionContext $context): ?string
    {
        return $value instanceof Tag ? $value->code : null;
    }

    public function fromScim(mixed $value, ConversionContext $context): ?Tag
    {
        return match (true) {
            null === $value => null,
            \is_string($value) => new Tag($value),
            default => throw new ConversionFailed('A tag is a string.'),
        };
    }
}
