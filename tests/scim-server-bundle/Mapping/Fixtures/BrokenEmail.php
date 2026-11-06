<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;

/**
 * One error per member.
 */
final class BrokenEmail
{
    #[ScimAttribute('valu')]
    public ?string $value = null;

    #[ScimAttribute('type', caseExact: true)]
    public ?string $type = null;

    #[ScimAttribute('display', primary: true)]
    public ?string $display = null;

    #[ScimAttribute('primary', default: 'yes')]
    public ?bool $primary = null;

    #[ScimAttribute('emails.display')]
    public ?string $label = null;

    #[ScimAttribute('display')]
    public function display(): string
    {
        return '';
    }
}
