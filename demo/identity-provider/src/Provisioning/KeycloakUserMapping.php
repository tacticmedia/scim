<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * The default mapping of scim-outbound-for-keycloak plus the custom attributes enterprise.employeeNumber and
 * enterprise.department. The plugin never sends a password, a phone number or a manager.
 *
 * @see https://github.com/shebang-labs/scim-outbound-for-keycloak/blob/main/docs/configuration.md
 */
final class KeycloakUserMapping
{
    /** The attributes of the plugin's ScimUser record. A PUT drops every other attribute. */
    private const array RECORD = ['schemas', 'id', 'externalId', 'userName', 'name', 'displayName', 'title', 'preferredLanguage', 'locale', 'emails'];
    private const array ENTERPRISE_RECORD = ['employeeNumber', 'costCenter', 'organization', 'division', 'department'];

    /**
     * A Keycloak id is a UUID.
     */
    public static function keycloakId(DirectoryUser|DirectoryGroup $entry): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', $entry->id);
    }

    /**
     * @return array<string, mixed> the POST body, or the PUT body with $id
     */
    public static function user(DirectoryUser $user, ?string $id = null): array
    {
        $enterprise = self::enterprise($user);

        return self::withoutNull([
            'schemas' => [] === $enterprise ? [Scim::USER] : [Scim::USER, Scim::ENTERPRISE_USER],
            'id' => $id,
            'externalId' => self::keycloakId($user),
            'userName' => $user->userName,
            'name' => ['givenName' => $user->givenName, 'familyName' => $user->familyName],
            'displayName' => self::displayName($user),
            'emails' => self::emails($user),
            'active' => $user->active,
            Scim::ENTERPRISE_USER => $enterprise ?: null,
        ]);
    }

    /**
     * A replace of every mapped attribute, changed or not. A cleared email stays at the service provider.
     *
     * @return array<string, mixed>
     */
    public static function replacePatch(DirectoryUser $user): array
    {
        $values = self::withoutNull([
            'userName' => $user->userName,
            'externalId' => self::keycloakId($user),
            'active' => $user->active,
            'displayName' => self::displayName($user),
            'name' => ['givenName' => $user->givenName, 'familyName' => $user->familyName],
            'emails' => self::emails($user),
            Scim::ENTERPRISE_USER => self::enterprise($user) ?: null,
        ]);

        return Scim::patch(array_map(static fn (string $path, mixed $value): array => ['op' => 'replace', 'path' => $path, 'value' => $value], array_keys($values), $values));
    }

    /**
     * @param array<mixed> $current the user at the service provider
     *
     * @return array<string, mixed>
     */
    public static function inactive(array $current): array
    {
        $enterprise = array_intersect_key(Json::object($current, Scim::ENTERPRISE_USER), array_flip(self::ENTERPRISE_RECORD));

        return self::withoutNull([
            ...array_intersect_key($current, array_flip(self::RECORD)),
            'active' => false,
            Scim::ENTERPRISE_USER => $enterprise ?: null,
        ]);
    }

    private static function displayName(DirectoryUser $user): string
    {
        return trim($user->givenName.' '.$user->familyName) ?: $user->userName;
    }

    /**
     * @return list<array{value: string, type: string, primary: true}>|null
     */
    private static function emails(DirectoryUser $user): ?array
    {
        return null === $user->workEmail ? null : [['value' => $user->workEmail, 'type' => 'work', 'primary' => true]];
    }

    /**
     * @return array<string, string>
     */
    private static function enterprise(DirectoryUser $user): array
    {
        return self::withoutNull(['employeeNumber' => $user->employeeNumber, 'department' => $user->department]);
    }

    /**
     * @template T
     *
     * @param array<array-key, T|null> $values
     *
     * @return array<array-key, T>
     */
    private static function withoutNull(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => null !== $value);
    }
}
