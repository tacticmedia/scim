<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

final class Scim
{
    public const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    public const string ENTERPRISE_USER = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    public const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    public const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return array<string, mixed>
     */
    public static function patch(array $operations): array
    {
        return ['schemas' => [self::PATCH_OP], 'Operations' => $operations];
    }

    public static function equals(string $attribute, string $value): string
    {
        return \sprintf('%s eq "%s"', $attribute, addcslashes($value, '"\\'));
    }
}
