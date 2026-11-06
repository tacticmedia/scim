<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

final readonly class Tag
{
    public function __construct(public string $code)
    {
    }
}
