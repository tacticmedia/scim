<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;

#[ScimResource('User')]
final class ElementAndSlot
{
    use UserBasics;

    #[ScimAttribute('emails[type eq "work"].value')]
    public ?string $workEmail = null;

    /** @var list<PlainEmail> */
    #[ScimAttribute('emails', elementClass: PlainEmail::class)]
    private array $emails = [];

    /**
     * @return list<PlainEmail>
     */
    public function getEmails(): array
    {
        return $this->emails;
    }

    public function addEmail(PlainEmail $email): void
    {
        $this->emails[] = $email;
    }

    public function removeEmail(PlainEmail $email): void
    {
        $this->emails = array_values(array_filter($this->emails, static fn (PlainEmail $kept): bool => $kept !== $email));
    }
}
