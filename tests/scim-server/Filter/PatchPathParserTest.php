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
        yield 'sub-attribute' => ['name.familyName', 'name.familyName', null];
        yield 'value path' => ['addresses[type eq "work"]', 'addresses', 'addresses.type eq "work"'];
        yield 'member value path' => ['members[value eq "'.self::MEMBER.'"]', 'members', 'members.value eq "'.self::MEMBER.'"'];
        yield 'value path with sub-attribute' => [
            'members[value eq "'.self::MEMBER.'"].displayName',
            'members.displayName',
            'members.value eq "'.self::MEMBER.'"',
        ];
        yield 'extension attribute' => [self::ENTERPRISE.':employeeNumber', self::ENTERPRISE.':employeeNumber', null];
        yield 'extension sub-attribute' => [self::ENTERPRISE.':manager.value', self::ENTERPRISE.':manager.value', null];
        yield 'URN value path with sub-attribute' => [self::CORE.'emails[type eq "work"].value', self::CORE.'emails.value', self::CORE.'emails.type eq "work"'];
        yield 'Entra email' => ['emails[type eq "work"].value', 'emails.value', 'emails.type eq "work"'];
        yield 'Entra sub-attribute case' => ['emails[type eq "other"].Value', 'emails.Value', 'emails.type eq "other"'];
        yield 'no space before value' => ['members[value eq"2819c223"]', 'members', 'members.value eq "2819c223"'];
        yield 'bracket inside a string' => ['emails[value eq "a]b"].display', 'emails.display', 'emails.value eq "a]b"'];
        yield 'open bracket inside a string' => ['emails[value eq "a[b"]', 'emails', 'emails.value eq "a[b"'];
        yield 'logical value filter' => [
            'emails[type eq "work" or (type eq "home" and value ew "@example.com")].display',
            'emails.display',
            '(emails.type eq "work" or (emails.type eq "home" and emails.value ew "@example.com"))',
        ];
        yield '$ref sub-attribute' => ['members[value eq "x"].$ref', 'members.$ref', 'members.value eq "x"'];
        yield '$ref sub-attribute in any case' => ['members[value eq "x"].$REF', 'members.$REF', 'members.value eq "x"'];
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
            'text after the value path' => 'emails[type eq "work"]junk',
            'two sub-attributes after the value path' => 'emails[type eq "work"].value.display',
            'value path on a sub-attribute' => 'name.givenName[value eq "x"]',
            'no attribute' => '[type eq "work"]',
            'leading space' => ' members',
            'closing bracket only' => 'emails]',
            'sub-attribute starts with a digit' => 'emails[type eq "work"].1st',
        ] as $name => $path) {
            yield $name => [$path, ScimErrorType::InvalidPath, \sprintf("'%s' is not a valid path. Expected attrPath, attrPath[valFilter] or attrPath[valFilter].subAttr.", $path)];
        }

        yield 'value filter without value' => ['emails[type eq]', ScimErrorType::InvalidFilter, 'Expected a comparison value (a JSON string, number, true, false or null), found the end of the filter.'];
        yield 'empty value filter' => ['emails[]', ScimErrorType::InvalidFilter, 'Expected an attribute path, found the end of the filter.'];
        yield 'nested value path' => ['emails[type[value eq "x"] pr]', ScimErrorType::InvalidFilter, "Value paths do not nest: found '[' after 'emails.type' at offset 4."];
        yield 'extra parenthesis' => ['emails[type eq "work")]', ScimErrorType::InvalidFilter, "Expected 'and', 'or' or the end of the filter at offset 14, found ')'."];
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
