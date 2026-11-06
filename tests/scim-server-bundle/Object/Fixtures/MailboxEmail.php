<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;

/**
 * "value" is not nullable and has no default, so "emails.value" is required. The constructor gets the owner by type
 * and "value" by name; $label has no mapping.
 */
final class MailboxEmail
{
    #[ScimAttribute('type')]
    public ?string $type = null;

    #[ScimAttribute('display')]
    public ?string $display = null;

    #[ScimAttribute('primary', default: false)]
    public bool $primary = false;

    public function __construct(
        public readonly Mailbox $mailbox,
        #[ScimAttribute('value')]
        public string $value,
        public string $label = 'mail',
    ) {
    }
}
