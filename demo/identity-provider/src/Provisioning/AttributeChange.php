<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

final readonly class AttributeChange
{
    /**
     * @param bool $added the service provider has no value yet
     */
    public function __construct(
        public string $path,
        public string|bool $value,
        public bool $added,
    ) {
    }

    public function filtered(): bool
    {
        return str_contains($this->path, '[');
    }
}
