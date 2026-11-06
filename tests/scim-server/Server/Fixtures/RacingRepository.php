<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server\Fixtures;

use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Repository\ResourceRepository;

/**
 * Each get() returns the stored document, then writes it back unchanged, as a concurrent request does between the
 * If-Match check of the server and its write.
 */
final readonly class RacingRepository implements ResourceRepository
{
    public function __construct(
        private InMemoryResourceRepository $inner,
    ) {
    }

    #[\Override]
    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array
    {
        $document = $this->inner->get($id);

        if (null !== $document) {
            $this->inner->replace($id, $document);
        }

        return $document;
    }

    #[\Override]
    public function list(ListQuery $query): ListResult
    {
        return $this->inner->list($query);
    }

    #[\Override]
    public function create(array $document): array
    {
        return $this->inner->create($document);
    }

    #[\Override]
    public function replace(string $id, array $document, ?string $version = null): array
    {
        return $this->inner->replace($id, $document, $version);
    }

    #[\Override]
    public function delete(string $id, ?string $version = null): void
    {
        $this->inner->delete($id, $version);
    }
}
