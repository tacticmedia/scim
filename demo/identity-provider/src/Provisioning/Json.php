<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

final class Json
{
    /**
     * @return array<mixed>|null
     */
    public static function decode(string $json): ?array
    {
        $value = json_decode($json, true);

        return \is_array($value) ? $value : null;
    }

    /**
     * @param array<mixed> $document
     */
    public static function string(array $document, string ...$keys): ?string
    {
        $value = $document;

        foreach ($keys as $key) {
            $value = \is_array($value) ? $value[$key] ?? null : null;
        }

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<mixed> $document
     *
     * @return array<mixed>
     */
    public static function object(array $document, string $key): array
    {
        $value = $document[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    /**
     * The value of the element with the given type of a multi-valued attribute.
     *
     * @param array<mixed> $document
     */
    public static function typedValue(array $document, string $attribute, string $type): ?string
    {
        foreach (self::object($document, $attribute) as $element) {
            if (\is_array($element) && $type === ($element['type'] ?? null)) {
                return self::string($element, 'value');
            }
        }

        return null;
    }

    public static function pretty(string $json): string
    {
        $value = self::decode($json);

        return null === $value ? $json : json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
