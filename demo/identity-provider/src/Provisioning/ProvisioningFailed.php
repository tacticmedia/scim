<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use TacticMedia\ScimDemo\IdentityProvider\Entity\ProvisioningLogEntry;

final class ProvisioningFailed extends \RuntimeException
{
    public static function of(ProvisioningLogEntry $entry): self
    {
        $request = $entry->method.' '.$entry->url;

        if (0 === $entry->status) {
            return new self(\sprintf('%s did not reach the service provider: %s', $request, $entry->responseBody));
        }

        $detail = Json::string(Json::decode($entry->responseBody) ?? [], 'detail') ?? 'no SCIM error detail';

        return new self(\sprintf('%s answered %d: %s', $request, $entry->status, $detail));
    }

    public static function withoutId(): self
    {
        return new self('The service provider returned a resource without an "id".');
    }

    public static function alreadyLinked(string $id, string $name, string $holderName): self
    {
        return new self(\sprintf('The service provider resource %s that matches "%s" is already linked to "%s".', $id, $name, $holderName));
    }

    public static function provisionedElsewhere(string $name, string $externalId): self
    {
        return new self(\sprintf('The service provider resource that matches "%s" has another externalId than %s. The IdP leaves it untouched.', $name, $externalId));
    }
}
