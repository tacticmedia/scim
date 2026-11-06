<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Converter\Fixtures;

use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

final readonly class RejectingHasher implements PasswordHasherInterface
{
    #[\Override]
    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        throw new InvalidPasswordException('Too simple.');
    }

    #[\Override]
    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        return false;
    }

    #[\Override]
    public function needsRehash(string $hashedPassword): bool
    {
        return false;
    }
}
