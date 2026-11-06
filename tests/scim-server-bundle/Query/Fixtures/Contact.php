<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Query\Fixtures;

use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\SchemaUrn;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Tag;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\TagConverter;

/**
 * The RFC User schema with a string id, a writeOnly attribute, slot groups whose primary group is declared last, a
 * writeOnly sub-attribute, and a typed and an untyped reference collection of one attribute.
 */
#[ScimResource(
    'Contact',
    schema: SchemaUrn::User->value,
    extensions: [new ScimExtension('urn:acme:scim:schemas:extension:badge:1.0:User', 'Badge')],
)]
final class Contact
{
    public string $id = '';

    #[ScimAttribute('userName')]
    public string $userName = '';

    #[ScimAttribute('password', converter: TagConverter::class)]
    public ?Tag $password = null;

    #[ScimAttribute('addresses[type eq "work"].locality')]
    public ?string $workLocality = null;

    #[ScimAttribute('addresses[type eq "work"].postalCode')]
    public ?string $workPostalCode = null;

    #[ScimAttribute('addresses[type eq "home"].locality', primary: true)]
    public ?string $homeLocality = null;

    #[ScimAttribute('addresses[type eq "home"].region')]
    public ?string $homeRegion = null;

    #[ScimAttribute('urn:acme:scim:schemas:extension:badge:1.0:User:badge.code', mutability: Mutability::WriteOnly)]
    public ?string $badgeCode = null;

    #[ScimAttribute('urn:acme:scim:schemas:extension:badge:1.0:User:badge.holder')]
    public ?string $badgeHolder = null;

    /** @var list<Group> */
    #[ScimAttribute('groups[type eq "direct"]')]
    public array $directGroups = [];

    /** @var list<Group> */
    #[ScimAttribute('groups')]
    public array $otherGroups = [];
}
