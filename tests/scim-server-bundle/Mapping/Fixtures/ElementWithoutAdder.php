<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;

#[ScimResource('User')]
final class ElementWithoutAdder
{
    use UserBasics;

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
}
