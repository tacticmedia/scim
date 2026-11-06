<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimExtension;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Enum\DeviceStatus;

/**
 * A resource type with an application core schema, an application extension, an int id with a prefix and a factory.
 */
#[ScimResource(
    'Device',
    schema: 'urn:acme:scim:schemas:core:1.0:Device',
    extensions: [new ScimExtension('urn:acme:scim:schemas:extension:audit:1.0:Device', 'DeviceAudit', 'Audit data', required: true)],
    factory: DeviceFactory::class,
    idPrefix: 'd',
    description: 'A device',
)]
final class Device
{
    #[ScimAttribute('id')]
    public ?int $id = null;

    #[ScimAttribute('externalId')]
    public ?string $externalId = null;

    #[ScimAttribute('status')]
    public ?DeviceStatus $status = null;

    #[ScimAttribute('enabled', default: true)]
    public bool $enabled = true;

    #[ScimAttribute('weight', default: 1.0)]
    public ?float $weight = null;

    #[ScimAttribute('location.building', description: 'Building code')]
    public ?string $building = null;

    #[ScimAttribute('location.floor', default: 0)]
    public ?int $floor = null;

    #[ScimAttribute('urn:acme:scim:schemas:extension:audit:1.0:Device:checkedBy', type: AttributeType::Reference, caseExact: true, returned: Returned::Request, uniqueness: Uniqueness::Global, canonicalValues: ['robot'])]
    public ?string $checkedBy = null;

    #[ScimAttribute('urn:acme:scim:schemas:extension:audit:1.0:Device:tag', converter: TagConverter::class)]
    public ?Tag $tag = null;

    #[ScimAttribute('urn:acme:scim:schemas:extension:audit:1.0:Device:note', converter: TagConverter::class)]
    public Tag|string|null $note = null;

    public function __construct(
        #[ScimAttribute('serialNumber', required: true, caseExact: true, uniqueness: Uniqueness::Server, description: 'Serial number')]
        public string $serialNumber,
        #[ScimAttribute('purchasedAt', default: '2026-01-01T00:00:00Z', mutability: Mutability::Immutable)]
        public readonly ?\DateTimeImmutable $purchasedAt = null,
    ) {
    }

    #[ScimAttribute('label')]
    public function label(): string
    {
        return $this->serialNumber;
    }
}
