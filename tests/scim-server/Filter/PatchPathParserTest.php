<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Filter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Filter\FilterParser;

final class PatchPathParserTest extends KernelTestCase
{
    use AssertsScimException;
    private const string CORE = 'urn:ietf:params:scim:schemas:core:2.0:User:';
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const string MEMBER = '2819c223-7f76-453a-919d-413861904646';

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function validPaths(): iterable
    {
        yield 'attribute' => ['members', 'members', null];
        yield 'value path' => ['addresses[type eq "work"]', 'addresses', 'addresses.type eq "work"'];
        yield 'value path with sub-attribute' => [
            'members[value eq "'.self::MEMBER.'"].displayName',
            'members.displayName',
            'members.value eq "'.self::MEMBER.'"',
        ];
        yield 'URN value path with sub-attribute' => [self::CORE.'emails[type eq "work"].value', self::CORE.'emails.value', self::CORE.'emails.type eq "work"'];
        yield 'no space before value' => ['members[value eq"2819c223"]', 'members', 'members.value eq "2819c223"'];
        yield 'bracket inside a string' => ['emails[value eq "a]b"].display', 'emails.display', 'emails.value eq "a]b"'];
        yield 'open bracket inside a string' => ['emails[value eq "a[b"]', 'emails', 'emails.value eq "a[b"'];
        yield '$ref sub-attribute' => ['members[value eq "x"].$ref', 'members.$ref', 'members.value eq "x"'];
        yield 'extension URN alone' => [self::ENTERPRISE, self::ENTERPRISE, null];
    }

    #[Test]
    #[DataProvider('validPaths')]
    #[TestDox('Parses the PATCH path $path')]
    public function parsesPatchPath(string $path, string $expectedPath, ?string $expectedValueFilter): void
    {
        $patchPath = new FilterParser()->parsePatchPath($path);

        self::assertSame(
            [$expectedPath, $expectedValueFilter],
            [(string) $patchPath->path, $patchPath->valueFilter instanceof \TacticMedia\ScimServer\Filter\Filter ? FilterNotation::of($patchPath->valueFilter) : null],
        );
    }

    /**
     * @return iterable<string, array{string, ScimErrorType, string}>
     */
    public static function invalidPaths(): iterable
    {
        foreach ([
            'empty' => '',
            'two sub-attributes' => 'name.givenName.x',
            'value path on a sub-attribute' => 'name.givenName[value eq "x"]',
        ] as $name => $path) {
            yield $name => [$path, ScimErrorType::InvalidPath, \sprintf("'%s' is not a valid path. Expected attrPath, attrPath[valFilter] or attrPath[valFilter].subAttr.", $path)];
        }

        yield 'value filter without value' => ['emails[type eq]', ScimErrorType::InvalidFilter, 'Expected a comparison value (a JSON string, number, true, false or null), found the end of the filter.'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valueFiltersAboveTheLimits(): iterable
    {
        yield '8193 bytes' => ['emails[value eq "'.str_repeat('a', 8182).'"]', 'The filter is longer than 8192 bytes.'];
        yield '65 nested groups' => ['emails['.str_repeat('(', 65).'type pr'.str_repeat(')', 65).'].value', 'The filter nests deeper than 64 levels at offset 64.'];
    }

    #[Test]
    #[DataProvider('valueFiltersAboveTheLimits')]
    #[TestDox('Rejects a PATCH value filter of $_dataName with invalidFilter')]
    public function rejectsValueFilterAboveTheLimits(string $path, string $detail): void
    {
        self::assertThrowsScimException(ScimErrorType::InvalidFilter, $detail, static fn (): mixed => new FilterParser()->parsePatchPath($path));
    }

    #[Test]
    #[DataProvider('invalidPaths')]
    #[TestDox('Rejects the PATCH path $path with $scimType')]
    public function rejectsPatchPath(string $path, ScimErrorType $scimType, string $detail): void
    {
        self::assertThrowsScimException($scimType, $detail, static fn (): mixed => new FilterParser()->parsePatchPath($path));
    }
}
