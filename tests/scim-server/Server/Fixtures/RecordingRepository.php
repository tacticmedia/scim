<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server\Fixtures;

use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Repository\ResourceRepository;

/**
 * Records the projection hints of get() and list() as "<id or list> attributes=<paths> excludedAttributes=<paths>",
 * and the version of replace() and delete() as "<method> <id> version=<version or null>".
 */
final class RecordingRepository implements ResourceRepository
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $writes = [];

    public function __construct(
        private readonly InMemoryResourceRepository $inner,
    ) {
    }

    #[\Override]
    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array
    {
        $this->record($id, $attributes, $excludedAttributes);

        return $this->inner->get($id);
    }

    #[\Override]
    public function list(ListQuery $query): ListResult
    {
        $this->record('list', $query->attributes, $query->excludedAttributes);

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
        $this->writes[] = \sprintf('replace %s version=%s', $id, $version ?? 'null');

        return $this->inner->replace($id, $document, $version);
    }

    #[\Override]
    public function delete(string $id, ?string $version = null): void
    {
        $this->writes[] = \sprintf('delete %s version=%s', $id, $version ?? 'null');
        $this->inner->delete($id, $version);
    }

    /**
     * @param list<AttributePath> $attributes
     * @param list<AttributePath> $excludedAttributes
     */
    private function record(string $call, array $attributes, array $excludedAttributes): void
    {
        $this->calls[] = \sprintf('%s attributes=%s excludedAttributes=%s', $call, implode(',', $attributes), implode(',', $excludedAttributes));
    }
}
