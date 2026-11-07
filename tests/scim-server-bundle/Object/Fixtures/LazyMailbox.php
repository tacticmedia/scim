<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\PlainEmail;

/**
 * An element collection that is null until it gets an element.
 */
#[ScimResource('User')]
final class LazyMailbox
{
    #[ScimAttribute('id')]
    public string $id = 'lazy-1';

    #[ScimAttribute('userName')]
    public string $userName = 'lazy';

    /** @var list<PlainEmail>|null */
    #[ScimAttribute('emails', elementClass: PlainEmail::class)]
    public ?array $emails = null;

    public function addEmail(PlainEmail $email): void
    {
        $this->emails[] = $email;
    }

    public function removeEmail(PlainEmail $email): void
    {
        $this->emails = array_values(array_filter($this->emails ?? [], static fn (PlainEmail $kept): bool => $kept !== $email));
    }
}
