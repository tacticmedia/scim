<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServer\Server\MeResolver;
use TacticMedia\ScimServer\Server\MeSubject;

final readonly class FixedMeResolver implements MeResolver
{
    public function __construct(
        private ?MeSubject $subject,
    ) {
    }

    #[\Override]
    public function resolve(Request $request): ?MeSubject
    {
        return $this->subject;
    }
}
