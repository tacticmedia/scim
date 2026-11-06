<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server\Fixtures;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;
use TacticMedia\ScimServer\Repository\DeltaRepository;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Repository\PatchResult;
use TacticMedia\ScimServer\Repository\ResourcePatch;
use TacticMedia\ScimServer\Repository\ValuesChange;
use TacticMedia\ScimServer\Repository\ValuesStep;

/**
 * Changes core attributes by delta on top of an InMemoryResourceRepository, with set semantics by "value". get()
 * leaves out the delta attributes that $excludedAttributes names. The value self::UNKNOWN names no resource.
 * Records the hints of get() as "<id> excludedAttributes=<paths>" and every ResourcePatch. With $reportsEveryStep,
 * patch() returns one ValuesChange per step, also for a step without effect.
 */
final class MemberDeltaRepository implements DeltaRepository
{
    public const string UNKNOWN = 'unknown-member';

    public bool $reportsEveryStep = false;

    /** @var list<string> */
    public array $gets = [];

    /** @var list<ResourcePatch> */
    public array $patches = [];

    /**
     * @param list<AttributePath> $deltaAttributes
     */
    public function __construct(
        private readonly InMemoryResourceRepository $inner,
        private readonly array $deltaAttributes,
    ) {
    }

    #[\Override]
    public function deltaAttributes(): array
    {
        return $this->deltaAttributes;
    }

    #[\Override]
    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array
    {
        $this->gets[] = \sprintf('%s excludedAttributes=%s', $id, implode(',', $excludedAttributes));
        $document = $this->inner->get($id);
        $excluded = array_values(array_filter($this->deltaAttributes, static fn (AttributePath $path): bool => array_any($excludedAttributes, $path->equals(...))));

        return null === $document ? null : $this->without($document, $excluded);
    }

    #[\Override]
    public function patch(string $id, ResourcePatch $patch): PatchResult
    {
        $this->patches[] = $patch;
        $stored = $this->stored($id, $patch->version);
        $document = [...($patch->document ?? $stored), ...$this->deltaValues($stored)];
        $changes = [];

        foreach ($patch->steps as $step) {
            [$document, $changes[]] = $this->applyStep($document, $step);
        }

        $changes = $this->reportsEveryStep ? $changes : array_values(array_filter($changes, static fn (ValuesChange $change): bool => [] !== $change->added || [] !== $change->removed));
        $written = null === $patch->document && [] === $changes ? $stored : $this->inner->replace($id, $document);

        return new PatchResult($this->without($written, $this->deltaAttributes), $changes);
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

    /**
     * @return array<string, mixed>
     */
    private function stored(string $id, ?string $version): array
    {
        $stored = $this->inner->get($id) ?? throw ScimException::notFound(\sprintf('Resource %s not found.', $id));
        $meta = \is_array($stored['meta'] ?? null) ? $stored['meta'] : [];

        return null === $version || $version === ($meta['version'] ?? null)
            ? $stored
            : throw ScimException::preconditionFailed('Failed to update. Resource has changed on the server.');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array{array<string, mixed>, ValuesChange}
     */
    private function applyStep(array $document, ValuesStep $step): array
    {
        $name = $step->path->attribute;
        $current = $this->elements($document[$name] ?? null);
        $given = array_column($step->values, null, 'value');

        if (\array_key_exists(self::UNKNOWN, $given) && PatchOperationType::Remove !== $step->type) {
            throw ScimException::invalidValue(\sprintf('No resource has the id "%s".', self::UNKNOWN));
        }

        $kept = match ($step->type) {
            PatchOperationType::Add => [...$current, ...array_diff_key($given, $current)],
            PatchOperationType::Remove => array_diff_key($current, $given),
            PatchOperationType::Replace => $given,
        };
        $document[$name] = array_values($kept);

        if ([] === $kept) {
            unset($document[$name]);
        }

        return [
            $document,
            new ValuesChange($step->path, array_values(array_diff_key($kept, $current)), array_values(array_diff_key($current, $kept))),
        ];
    }

    /**
     * @return array<array-key, array<string, mixed>> by "value"
     */
    private function elements(mixed $values): array
    {
        $elements = \is_array($values) ? array_filter($values, \is_array(...)) : [];

        return array_column($elements, null, 'value');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function deltaValues(array $document): array
    {
        return array_intersect_key($document, array_flip(array_map(static fn (AttributePath $path): string => $path->attribute, $this->deltaAttributes)));
    }

    /**
     * @param array<string, mixed> $document
     * @param list<AttributePath>  $paths
     *
     * @return array<string, mixed>
     */
    private function without(array $document, array $paths): array
    {
        return array_diff_key($document, array_flip(array_map(static fn (AttributePath $path): string => $path->attribute, $paths)));
    }
}
