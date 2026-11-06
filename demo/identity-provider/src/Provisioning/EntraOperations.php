<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

/**
 * The PATCH operations of Entra. The aadOptscim062020 flag changes four request types: disable user, add a
 * single-value attribute, replace several attributes, remove a group member.
 *
 * @see https://learn.microsoft.com/en-us/entra/identity/app-provisioning/application-provisioning-config-problem-scim-compatibility
 */
final class EntraOperations
{
    /**
     * @param list<AttributeChange> $changes
     *
     * @return list<array<string, mixed>>
     */
    public static function attributes(array $changes, Dialect $dialect): array
    {
        $replaced = array_values(array_filter($changes, static fn (AttributeChange $change): bool => !$change->added && !$change->filtered()));

        if (Dialect::EntraFlag !== $dialect || \count($replaced) < 2) {
            return array_map(static fn (AttributeChange $change): array => self::operation($change, $dialect), $changes);
        }

        $separate = array_filter($changes, static fn (AttributeChange $change): bool => !\in_array($change, $replaced, true));
        $values = array_combine(
            array_map(static fn (AttributeChange $change): string => $change->path, $replaced),
            array_map(static fn (AttributeChange $change): string|bool => $change->value, $replaced),
        );

        return [...array_map(static fn (AttributeChange $change): array => self::operation($change, $dialect), array_values($separate)), ['op' => 'replace', 'value' => $values]];
    }

    /**
     * @param list<string> $addedIds
     * @param list<string> $removedIds
     *
     * @return list<array<string, mixed>>
     */
    public static function members(array $addedIds, array $removedIds, Dialect $dialect): array
    {
        $operations = [];

        if ([] !== $addedIds) {
            $operations[] = ['op' => $dialect->operation('add'), 'path' => 'members', 'value' => self::memberValues($addedIds)];
        }

        if (Dialect::EntraFlag === $dialect) {
            return [...$operations, ...array_map(static fn (string $id): array => ['op' => 'remove', 'path' => \sprintf('members[value eq "%s"]', $id)], $removedIds)];
        }

        if ([] !== $removedIds) {
            $operations[] = ['op' => 'Remove', 'path' => 'members', 'value' => self::memberValues($removedIds)];
        }

        return $operations;
    }

    /**
     * @return array<string, mixed>
     */
    private static function operation(AttributeChange $change, Dialect $dialect): array
    {
        $operation = $change->added && (Dialect::EntraFlag !== $dialect || !$change->filtered()) ? 'add' : 'replace';

        return ['op' => $dialect->operation($operation), 'path' => $change->path, 'value' => \is_bool($change->value) ? $dialect->boolean($change->value) : $change->value];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array{'$ref': null, value: string}>
     */
    private static function memberValues(array $ids): array
    {
        return array_map(static fn (string $id): array => ['$ref' => null, 'value' => $id], $ids);
    }
}
