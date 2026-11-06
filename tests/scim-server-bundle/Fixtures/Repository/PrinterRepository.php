<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Repository;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;
use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;

/**
 * A raw repository of one printer, with an application core schema and extension.
 */
#[AsResourceRepository(
    'Printer',
    attributes: ['serialNumber', 'urn:acme:scim:schemas:extension:site:1.0:Printer:room'],
    schema: 'urn:acme:scim:schemas:core:1.0:Printer',
    schemas: [
        new Schema('urn:acme:scim:schemas:core:1.0:Printer', 'Printer', 'A printer', [new AttributeDefinition('serialNumber', caseExact: true), new AttributeDefinition('model')]),
        new Schema('urn:acme:scim:schemas:extension:site:1.0:Printer', 'PrinterSite', 'Where a printer is', [new AttributeDefinition('room')]),
    ],
    description: 'Printers',
)]
final readonly class PrinterRepository implements ResourceRepository
{
    private const array PRINTER = ['id' => 'p1', 'serialNumber' => 'SN-1', 'urn:acme:scim:schemas:extension:site:1.0:Printer' => ['room' => '4.01']];

    #[\Override]
    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array
    {
        return self::PRINTER['id'] === $id ? self::PRINTER : null;
    }

    #[\Override]
    public function list(ListQuery $query): ListResult
    {
        return new ListResult([self::PRINTER], 1);
    }

    #[\Override]
    public function create(array $document): array
    {
        throw ScimException::notImplemented('Printers are read-only.');
    }

    #[\Override]
    public function replace(string $id, array $document, ?string $version = null): array
    {
        throw ScimException::notImplemented('Printers are read-only.');
    }

    #[\Override]
    public function delete(string $id, ?string $version = null): void
    {
        throw ScimException::notImplemented('Printers are read-only.');
    }
}
