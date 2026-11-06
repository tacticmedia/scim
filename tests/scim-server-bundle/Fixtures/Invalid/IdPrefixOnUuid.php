<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

#[ScimResource('User', idPrefix: 'u')]
final class IdPrefixOnUuid
{
    #[ScimAttribute('userName')]
    public string $userName = '';

    public function __construct(public Uuid $id = new Uuid('0193a0f5-f7a5-7b5e-9c1e-0a1b2c3d4e5f'))
    {
    }
}
