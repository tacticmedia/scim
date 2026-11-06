<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * The app profile of an Okta OIN SCIM 2.0 integration.
 *
 * @see https://developer.okta.com/docs/api/openapi/okta-scim/guides/scim-20/
 */
final class OktaUserMapping
{
    /**
     * @return array<string, mixed>
     */
    public static function createBody(DirectoryUser $user): array
    {
        $enterprise = self::enterprise($user, []);

        return array_filter([
            'schemas' => [] === $enterprise ? [Scim::USER] : [Scim::USER, Scim::ENTERPRISE_USER],
            'userName' => $user->userName,
            'name' => ['givenName' => $user->givenName, 'familyName' => $user->familyName],
            'emails' => self::withTyped([], 'work', $user->workEmail, ['primary' => true]) ?: null,
            'displayName' => $user->displayName,
            'locale' => 'en-US',
            'externalId' => \sprintf('00u%017d', $user->id),
            'groups' => [],
            'password' => $user->demoPlainTextPassword,
            'active' => $user->active,
            'phoneNumbers' => self::withTyped([], 'mobile', $user->mobilePhone) ?: null,
            Scim::ENTERPRISE_USER => $enterprise ?: null,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * Okta changes the profile attributes in the resource that the service provider returned, and keeps the rest,
     * "id", "meta" and "groups" included.
     *
     * @param array<mixed> $current
     *
     * @return array<mixed>
     */
    public static function profile(array $current, DirectoryUser $user): array
    {
        $profile = [
            ...$current,
            'userName' => $user->userName,
            'name' => [...Json::object($current, 'name'), 'givenName' => $user->givenName, 'familyName' => $user->familyName],
            'displayName' => $user->displayName,
            'active' => $user->active,
        ];
        $optional = [
            'emails' => self::withTyped(Json::object($current, 'emails'), 'work', $user->workEmail, ['primary' => true]),
            'phoneNumbers' => self::withTyped(Json::object($current, 'phoneNumbers'), 'mobile', $user->mobilePhone),
            Scim::ENTERPRISE_USER => self::enterprise($user, Json::object($current, Scim::ENTERPRISE_USER)),
        ];

        foreach ($optional as $attribute => $value) {
            $profile[$attribute] = $value;

            if ([] === $value) {
                unset($profile[$attribute]);
            }
        }

        return $profile;
    }

    /**
     * @param array<mixed>        $elements
     * @param array<string, bool> $extra
     *
     * @return array<mixed>
     */
    private static function withTyped(array $elements, string $type, ?string $value, array $extra = []): array
    {
        $others = array_values(array_filter($elements, static fn (mixed $element): bool => !\is_array($element) || $type !== ($element['type'] ?? null)));

        if (null === $value) {
            return $others;
        }

        if (\count($others) === \count($elements)) {
            return [...$elements, [...$extra, 'value' => $value, 'type' => $type]];
        }

        return array_map(static fn (mixed $element): mixed => \is_array($element) && $type === ($element['type'] ?? null) ? [...$element, 'value' => $value] : $element, $elements);
    }

    /**
     * A manager that is not provisioned yet keeps the current value.
     *
     * @param array<mixed> $current
     *
     * @return array<mixed>
     */
    private static function enterprise(DirectoryUser $user, array $current): array
    {
        $enterprise = [...$current, 'department' => $user->department, 'employeeNumber' => $user->employeeNumber];
        $managerId = $user->manager?->scimId;

        if (!$user->manager instanceof DirectoryUser) {
            unset($enterprise['manager']);
        } elseif (null !== $managerId && $managerId !== Json::string($current, 'manager', 'value')) {
            $enterprise['manager'] = ['value' => $managerId];
        }

        return array_filter($enterprise, static fn (mixed $value): bool => null !== $value);
    }
}
