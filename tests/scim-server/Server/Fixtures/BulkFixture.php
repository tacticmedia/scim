<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Event\ResourceCreated;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Server\ScimServer;

/**
 * Bulk helpers for a test that uses {@see ServerFixture}.
 */
trait BulkFixture
{
    private const string BULK_REQUEST = 'urn:ietf:params:scim:api:messages:2.0:BulkRequest';
    private const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, mixed>       $members
     */
    private function bulk(ScimServer $server, array $operations, array $members = []): Response
    {
        return $this->send($server, 'POST', '/Bulk', ['schemas' => [self::BULK_REQUEST], ...$members, 'Operations' => $operations]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function operations(Response $response): array
    {
        $operations = self::json($response)['Operations'] ?? null;
        self::assertIsList($operations);

        return array_map(static fn (mixed $operation): array => \is_array($operation) ? $operation : [], $operations);
    }

    private function idOf(InMemoryResourceRepository $repository, string $attribute, string $value): string
    {
        $document = array_find($repository->list(new ListQuery())->resources, static fn (array $document): bool => ($document[$attribute] ?? null) === $value);
        $id = ($document ?? [])['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<string> "<event> <displayName or userName>", in dispatch order
     */
    private function eventLog(): array
    {
        return array_map(static fn (object $event): string => match (true) {
            $event instanceof ResourceCreated => 'Created '.self::label($event->resource),
            $event instanceof ResourceUpdated => 'Updated '.self::label($event->after),
            default => $event::class,
        }, $this->events);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function label(array $document): string
    {
        $label = $document['displayName'] ?? $document['userName'] ?? null;

        return \is_string($label) ? $label : '';
    }
}
