<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server\Fixtures;

use TacticMedia\ScimServer\Query\CursorPage;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;
use TacticMedia\ScimServer\Repository\CursorPaginatedRepository;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;

/**
 * listAfter() answers from the inner repository while $native is true, else null.
 */
final class SwitchableCursorRepository implements CursorPaginatedRepository
{
    public bool $native = true;

    public function __construct(
        private readonly InMemoryResourceRepository $inner,
    ) {
    }

    #[\Override]
    public function listAfter(ListQuery $query, ?array $after): ?CursorPage
    {
        return $this->native ? $this->inner->listAfter($query, $after) : null;
    }

    #[\Override]
    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array
    {
        return $this->inner->get($id);
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
