<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures;

use Symfony\Component\PropertyAccess\PropertyAccess;
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Object\ObjectPage;
use TacticMedia\ScimServerBundle\Object\ObjectStore;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;

/**
 * Objects in memory, by resource type and SCIM id, for reference lookups. Records every findMany() call.
 */
final class ArrayObjectStore implements ObjectStore
{
    /** @var list<array{string, list<string>}> resource type name and ids of each findMany() call */
    public array $lookups = [];

    /** @var array<string, array<string, object>> */
    private array $objects = [];

    public function add(ResourceMapping $mapping, object ...$objects): self
    {
        foreach ($objects as $object) {
            $id = new IdConverter()->toScim(PropertyAccess::createPropertyAccessor()->getValue($object, $mapping->id->member), new ConversionContext($mapping, $mapping->id, $object));
            $this->objects[$mapping->name()][(string) $id] = $object;
        }

        return $this;
    }

    #[\Override]
    public function find(ResourceMapping $mapping, string $id): ?object
    {
        return $this->objects[$mapping->name()][$id] ?? null;
    }

    #[\Override]
    public function findMany(ResourceMapping $mapping, array $ids): array
    {
        $this->lookups[] = [$mapping->name(), $ids];

        return array_intersect_key($this->objects[$mapping->name()] ?? [], array_flip($ids));
    }

    #[\Override]
    public function query(ResourceMapping $mapping, ObjectQuery $query): ObjectPage
    {
        throw new \LogicException('ArrayObjectStore does not evaluate queries.');
    }

    #[\Override]
    public function transactional(ResourceMapping $mapping, \Closure $write): mixed
    {
        return $write();
    }

    #[\Override]
    public function insert(ResourceMapping $mapping, object $object): void
    {
        $this->add($mapping, $object);
    }

    #[\Override]
    public function update(ResourceMapping $mapping, object $object, mixed $version = null): void
    {
    }

    #[\Override]
    public function delete(ResourceMapping $mapping, object $object, mixed $version = null): void
    {
        $this->objects[$mapping->name()] = array_filter($this->objects[$mapping->name()] ?? [], static fn (object $stored): bool => $stored !== $object);
    }
}
