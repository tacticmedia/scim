<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use TacticMedia\ScimServerBundle\Converter\HashPassword;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User')]
final class ConstructorGetsPassword implements PasswordAuthenticatedUserInterface
{
    use UserBasics;

    public function __construct(
        #[ScimAttribute('password', converter: HashPassword::class)]
        public string $password,
    ) {
    }

    public function getPassword(): string
    {
        return $this->password;
    }
}
