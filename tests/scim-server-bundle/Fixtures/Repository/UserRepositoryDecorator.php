<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Repository;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use TacticMedia\ScimServer\Query\CursorPage;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;
use TacticMedia\ScimServer\Repository\CursorPaginatedRepository;
use TacticMedia\ScimServer\Repository\ResourceRepository;

#[AsDecorator('tacticmedia_scim_server.repository.user')]
final readonly class UserRepositoryDecorator implements CursorPaginatedRepository
{
    public function __construct(
        #[AutowireDecorated]
        public ResourceRepository $inner,
    ) {
    }

    #[\Override]
    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array
    {
        return $this->inner->get($id, $attributes, $excludedAttributes);
    }

    #[\Override]
    public function list(ListQuery $query): ListResult
    {
        return $this->inner->list($query);
    }

    #[\Override]
    public function listAfter(ListQuery $query, ?array $after): ?CursorPage
    {
        return $this->inner instanceof CursorPaginatedRepository ? $this->inner->listAfter($query, $after) : null;
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
