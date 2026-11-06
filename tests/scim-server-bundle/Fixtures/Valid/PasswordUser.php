<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use TacticMedia\ScimServerBundle\Converter\HashPassword;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * A hashed password that is also an optional constructor parameter.
 */
#[ScimResource('User')]
final class PasswordUser implements PasswordAuthenticatedUserInterface
{
    public string $id = 'u1';

    public function __construct(
        #[ScimAttribute('userName')]
        public string $userName,
        #[ScimAttribute('password', converter: HashPassword::class)]
        public ?string $password = null,
    ) {
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }
}
