<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Command\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;

#[ScimResource('User')]
final class TaggedEmailUser
{
    use UserBasics;

    /** @var list<TaggedEmail> */
    #[ScimAttribute('emails', elementClass: TaggedEmail::class)]
    private array $emails = [];

    /**
     * @return list<TaggedEmail>
     */
    public function getEmails(): array
    {
        return $this->emails;
    }

    public function addEmail(TaggedEmail $email): void
    {
        $this->emails[] = $email;
    }

    public function removeEmail(TaggedEmail $email): void
    {
        $this->emails = array_values(array_filter($this->emails, static fn (TaggedEmail $kept): bool => $kept !== $email));
    }
}
