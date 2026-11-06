<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid;

use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;

/**
 * The ResourceRepository methods, so that a raw repository fixture shows only its declaration.
 */
trait RepositoryBasics
{
    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array
    {
        return null;
    }

    public function list(ListQuery $query): ListResult
    {
        return new ListResult([], 0);
    }

    public function create(array $document): array
    {
        return $document;
    }

    public function replace(string $id, array $document, ?string $version = null): array
    {
        return $document;
    }

    public function delete(string $id, ?string $version = null): void
    {
    }
}
