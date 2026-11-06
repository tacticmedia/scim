<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\Plan\AllOf;
use TacticMedia\ScimServerBundle\Query\Plan\AnyOf;
use TacticMedia\ScimServerBundle\Query\Plan\Compare;
use TacticMedia\ScimServerBundle\Query\Plan\Exists;
use TacticMedia\ScimServerBundle\Query\Plan\IsPresent;
use TacticMedia\ScimServerBundle\Query\Plan\Negate;
use TacticMedia\ScimServerBundle\Query\Plan\Node;

/**
 * Builds plan trees by hand, as contracts 5.3 plans them, from paths without schema URN.
 */
final readonly class Plans
{
    public function __construct(
        private MappingRegistry $mappings,
        private string $resource,
        private Directory $directory,
    ) {
    }

    public function mapping(): ResourceMapping
    {
        return $this->mappings->get($this->resource);
    }

    /**
     * @param string      $path "userName", "emails.value", "department", "members"
     * @param string|null $type the value filter literal of a slot or a reference collection
     */
    public function at(string $path, ?string $type = null): AttributeMapping
    {
        $matches = static fn (AttributeMapping $mapping): bool => 0 === strcasecmp($mapping->path->attribute.(null === $mapping->path->subAttribute ? '' : '.'.$mapping->path->subAttribute), $path)
            && (null === $type || 0 === strcasecmp((string) $mapping->type, $type));

        return array_find($this->mapping()->attributes, $matches) ?? $this->inElement($path) ?? throw new \LogicException(\sprintf('%s has no mapping at "%s".', $this->resource, $path));
    }

    /**
     * "addresses.locality": the mapping of the sub-attribute in the element class of the element collection.
     */
    private function inElement(string $path): ?AttributeMapping
    {
        $parts = explode('.', $path, 2);
        $collection = array_find($this->mapping()->attributes, static fn (AttributeMapping $mapping): bool => $mapping->element instanceof \TacticMedia\ScimServerBundle\Mapping\ElementMapping && 0 === strcasecmp($mapping->path->attribute, $parts[0]));

        return null === $collection || !isset($parts[1]) ? null : $collection->element?->attributeAt($collection->path->withSubAttribute($parts[1]));
    }

    public function compare(string $path, ComparisonOperator $operator, mixed $value, ?string $type = null): Compare
    {
        return new Compare($this->at($path, $type), $operator, $value);
    }

    public function eq(string $path, mixed $value, ?string $type = null): Compare
    {
        return $this->compare($path, ComparisonOperator::Equal, $value, $type);
    }

    public function present(string $path, ?string $type = null): IsPresent
    {
        return new IsPresent($this->at($path, $type));
    }

    public function exists(string $path, ?Node $condition = null, ?string $type = null): Exists
    {
        return new Exists($this->at($path, $type), $condition);
    }

    /**
     * The id of $resource equals the id of the user or group $name.
     */
    public function idOf(string $resource, string $name): Compare
    {
        return new Compare($this->mappings->get($resource)->id, ComparisonOperator::Equal, $this->directory->id($name));
    }

    public function all(Node ...$nodes): AllOf
    {
        return new AllOf(array_values($nodes));
    }

    public function any(Node ...$nodes): AnyOf
    {
        return new AnyOf(array_values($nodes));
    }

    public function not(Node $node): Negate
    {
        return new Negate($node);
    }
}
