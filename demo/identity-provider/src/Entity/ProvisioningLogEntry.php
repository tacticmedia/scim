<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Json;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningLogEntryRepository;

#[ORM\Entity(repositoryClass: ProvisioningLogEntryRepository::class)]
final class ProvisioningLogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * @param array<string, string> $requestHeaders without the token
     * @param int                   $status         0 when the service provider was not reachable
     */
    public function __construct(
        #[ORM\Column]
        public readonly \DateTimeImmutable $time,
        #[ORM\Column(enumType: Dialect::class)]
        public readonly Dialect $dialect,
        #[ORM\Column]
        public readonly string $method,
        #[ORM\Column(type: Types::TEXT)]
        public readonly string $url,
        #[ORM\Column(type: Types::JSON)]
        public readonly array $requestHeaders,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public readonly ?string $requestBody,
        #[ORM\Column]
        public readonly int $status,
        #[ORM\Column(type: Types::TEXT)]
        public readonly string $responseBody,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function formattedRequestBody(): string
    {
        return Json::pretty($this->requestBody ?? '');
    }

    public function formattedResponseBody(): string
    {
        return Json::pretty($this->responseBody);
    }
}
