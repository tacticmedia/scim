<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * The attribute mapping of the Entra non-gallery SCIM application. Entra sends no null values.
 *
 * @see https://learn.microsoft.com/en-us/entra/identity/app-provisioning/use-scim-to-provision-users-and-groups
 */
final class EntraUserMapping
{
    private const string WORK_EMAIL = 'emails[type eq "work"].value';
    private const string MOBILE_PHONE = 'phoneNumbers[type eq "mobile"].value';
    private const string DEPARTMENT = Scim::ENTERPRISE_USER.':department';
    private const string EMPLOYEE_NUMBER = Scim::ENTERPRISE_USER.':employeeNumber';
    private const string MANAGER = Scim::ENTERPRISE_USER.':manager';

    /**
     * The mailNickname of the default mapping.
     */
    public static function externalId(DirectoryUser $user): string
    {
        return strstr($user->userName, '@', true) ?: $user->userName;
    }

    /**
     * @return array<string, mixed>
     */
    public static function createBody(DirectoryUser $user): array
    {
        return array_filter([
            'schemas' => [Scim::USER, Scim::ENTERPRISE_USER],
            'externalId' => self::externalId($user),
            'userName' => $user->userName,
            'active' => $user->active,
            'displayName' => $user->displayName,
            'emails' => self::typed($user->workEmail, 'work', ['primary' => true]),
            'phoneNumbers' => self::typed($user->mobilePhone, 'mobile'),
            'meta' => ['resourceType' => 'User'],
            'name' => ['formatted' => $user->givenName.' '.$user->familyName, 'familyName' => $user->familyName, 'givenName' => $user->givenName],
            'roles' => [],
            Scim::ENTERPRISE_USER => array_filter(['department' => $user->department, 'employeeNumber' => $user->employeeNumber]) ?: null,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param array<mixed> $current the resource at the service provider
     *
     * @return list<AttributeChange>
     */
    public static function changes(DirectoryUser $user, array $current): array
    {
        $before = self::values($current);
        $changes = [];

        foreach (self::desired($user) as $path => $value) {
            if ($value !== $before[$path]) {
                $changes[] = new AttributeChange($path, $value, null === $before[$path]);
            }
        }

        return $changes;
    }

    /**
     * Entra skips the manager until the manager is provisioned. It sends the manager id as a bare string.
     *
     * @param array<mixed> $current the resource at the service provider
     *
     * @return array<string, string>|null
     */
    public static function managerOperation(DirectoryUser $user, array $current, Dialect $dialect): ?array
    {
        $managerId = $user->manager?->scimId;
        $currentManagerId = Json::string($current, Scim::ENTERPRISE_USER, 'manager', 'value') ?? Json::string($current, Scim::ENTERPRISE_USER, 'manager');

        if ($managerId === $currentManagerId || ($user->manager instanceof DirectoryUser && null === $managerId)) {
            return null;
        }

        if (null === $managerId) {
            return ['op' => $dialect->operation('remove'), 'path' => self::MANAGER];
        }

        return ['op' => $dialect->operation(null === $currentManagerId ? 'add' : 'replace'), 'path' => self::MANAGER, 'value' => $managerId];
    }

    /**
     * @param array<string, bool> $extra
     *
     * @return list<array<string, string|bool>>|null
     */
    private static function typed(?string $value, string $type, array $extra = []): ?array
    {
        return null === $value ? null : [[...$extra, 'type' => $type, 'value' => $value]];
    }

    /**
     * @return array<string, string|bool>
     */
    private static function desired(DirectoryUser $user): array
    {
        return array_filter([
            'userName' => $user->userName,
            'externalId' => self::externalId($user),
            'displayName' => $user->displayName,
            'name.givenName' => $user->givenName,
            'name.familyName' => $user->familyName,
            self::WORK_EMAIL => $user->workEmail,
            self::MOBILE_PHONE => $user->mobilePhone,
            self::DEPARTMENT => $user->department,
            self::EMPLOYEE_NUMBER => $user->employeeNumber,
            'active' => $user->active,
        ], static fn (string|bool|null $value): bool => null !== $value);
    }

    /**
     * @param array<mixed> $document
     *
     * @return array<string, string|bool|null>
     */
    private static function values(array $document): array
    {
        $active = $document['active'] ?? null;

        return [
            'userName' => Json::string($document, 'userName'),
            'externalId' => Json::string($document, 'externalId'),
            'displayName' => Json::string($document, 'displayName'),
            'name.givenName' => Json::string($document, 'name', 'givenName'),
            'name.familyName' => Json::string($document, 'name', 'familyName'),
            self::WORK_EMAIL => Json::typedValue($document, 'emails', 'work'),
            self::MOBILE_PHONE => Json::typedValue($document, 'phoneNumbers', 'mobile'),
            self::DEPARTMENT => Json::string($document, Scim::ENTERPRISE_USER, 'department'),
            self::EMPLOYEE_NUMBER => Json::string($document, Scim::ENTERPRISE_USER, 'employeeNumber'),
            'active' => \is_bool($active) ? $active : null,
        ];
    }
}
