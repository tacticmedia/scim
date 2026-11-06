<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * An element collection in a PHP array, written through an adder and a remover.
 */
#[ScimResource('User')]
final class Mailbox
{
    #[ScimAttribute('id')]
    public string $id = 'm-1';

    #[ScimAttribute('userName')]
    public string $userName = 'mailbox';

    /** @var list<MailboxEmail> */
    #[ScimAttribute('emails', elementClass: MailboxEmail::class)]
    private array $emails = [];

    /**
     * @return list<MailboxEmail>
     */
    public function getEmails(): array
    {
        return $this->emails;
    }

    public function addEmail(MailboxEmail $email): void
    {
        $this->emails[] = $email;
    }

    public function removeEmail(MailboxEmail $email): void
    {
        $this->emails = array_values(array_filter($this->emails, static fn (MailboxEmail $kept): bool => $kept !== $email));
    }
}
