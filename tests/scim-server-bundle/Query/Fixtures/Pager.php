<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Query\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * An element collection whose members can all be absent.
 */
#[ScimResource('Pager', schema: 'urn:ietf:params:scim:schemas:core:2.0:User')]
final class Pager
{
    public string $id = '';

    #[ScimAttribute('userName')]
    public string $userName = '';

    /** @var list<PagerIm> */
    #[ScimAttribute('ims', elementClass: PagerIm::class)]
    public array $ims = [];

    public function addIm(PagerIm $im): void
    {
        $this->ims[] = $im;
    }

    public function removeIm(PagerIm $im): void
    {
        $this->ims = array_values(array_filter($this->ims, static fn (PagerIm $kept): bool => $kept !== $im));
    }
}
