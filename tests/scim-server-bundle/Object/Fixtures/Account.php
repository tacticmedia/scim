<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object\Fixtures;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServerBundle\Converter\HashPassword;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * A string id, a hashed writeOnly password, a slot group with two members, an immutable attribute, a DateTime
 * member and constructor parameters with default values.
 */
#[ScimResource('User', extensions: [new ScimExtension(self::EXTENSION, 'Account')])]
final class Account implements PasswordAuthenticatedUserInterface
{
    public const string EXTENSION = 'urn:acme:scim:schemas:extension:account:1.0:User';

    #[ScimAttribute('id')]
    public string $id;

    #[ScimAttribute('password', converter: HashPassword::class)]
    public ?string $password = null;

    #[ScimAttribute('addresses[type eq "work"].streetAddress', primary: true)]
    public ?string $street = null;

    #[ScimAttribute('addresses[type eq "work"].postalCode', default: '0000')]
    public ?string $postalCode = null;

    #[ScimAttribute(self::EXTENSION.':badge', mutability: Mutability::Immutable)]
    public ?string $badge = null;

    public function __construct(
        #[ScimAttribute('userName')]
        public string $userName,
        #[ScimAttribute('displayName')]
        public ?string $displayName = 'Anonymous',
        #[ScimAttribute('nickName', default: 'nick')]
        public string $nickName = 'unused',
        #[ScimAttribute(self::EXTENSION.':hiredAt')]
        public ?\DateTime $hiredAt = null,
        public string $locale = 'en',
    ) {
        $this->id = 'acc-'.$userName;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }
}
