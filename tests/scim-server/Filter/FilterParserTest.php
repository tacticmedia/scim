<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Filter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Filter\FilterParser;

final class FilterParserTest extends KernelTestCase
{
    use AssertsScimException;
    private const string CORE = 'urn:ietf:params:scim:schemas:core:2.0:User:';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rfc7644Figure2(): iterable
    {
        yield 'eq' => ['userName eq "bjensen"', 'userName eq "bjensen"'];
        yield 'co with apostrophe' => ['name.familyName co "O\'Malley"', 'name.familyName co "O\'Malley"'];
        yield 'sw' => ['userName sw "J"', 'userName sw "J"'];
        yield 'sw with URN' => [self::CORE.'userName sw "J"', self::CORE.'userName sw "J"'];
        yield 'pr' => ['title pr', 'title pr'];
        yield 'gt' => ['meta.lastModified gt "2011-05-13T04:42:34Z"', 'meta.lastModified gt "2011-05-13T04:42:34Z"'];
        yield 'ge' => ['meta.lastModified ge "2011-05-13T04:42:34Z"', 'meta.lastModified ge "2011-05-13T04:42:34Z"'];
        yield 'lt' => ['meta.lastModified lt "2011-05-13T04:42:34Z"', 'meta.lastModified lt "2011-05-13T04:42:34Z"'];
        yield 'le' => ['meta.lastModified le "2011-05-13T04:42:34Z"', 'meta.lastModified le "2011-05-13T04:42:34Z"'];
        yield 'and' => ['title pr and userType eq "Employee"', '(title pr and userType eq "Employee")'];
        yield 'or' => ['title pr or userType eq "Intern"', '(title pr or userType eq "Intern")'];
        yield 'schemas' => [
            "\n schemas eq \"urn:ietf:params:scim:schemas:extension:enterprise:2.0:User\"",
            'schemas eq "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User"',
        ];
        yield 'and group' => [
            "userType eq \"Employee\" and (emails co \"example.com\" or\n  emails.value co \"example.org\")",
            '(userType eq "Employee" and (emails co "example.com" or emails.value co "example.org"))',
        ];
        yield 'and not group' => [
            "userType ne \"Employee\" and not (emails co \"example.com\" or\n  emails.value co \"example.org\")",
            '(userType ne "Employee" and not((emails co "example.com" or emails.value co "example.org")))',
        ];
        yield 'and group of one' => ['userType eq "Employee" and (emails.type eq "work")', '(userType eq "Employee" and emails.type eq "work")'];
        yield 'and value path' => [
            "userType eq \"Employee\" and emails[type eq \"work\" and\n  value co \"@example.com\"]",
            '(userType eq "Employee" and emails[(emails.type eq "work" and emails.value co "@example.com")])',
        ];
        yield 'value path or value path' => [
            "emails[type eq \"work\" and value co \"@example.com\"] or\n  ims[type eq \"xmpp\" and value co \"@foo.com\"]",
            '(emails[(emails.type eq "work" and emails.value co "@example.com")] or ims[(ims.type eq "xmpp" and ims.value co "@foo.com")])',
        ];
        yield 'errata 7322 group in value path' => [
            'emails[type eq "work" or (type eq "home" and value ew "@example.com")]',
            'emails[(emails.type eq "work" or (emails.type eq "home" and emails.value ew "@example.com"))]',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function precedence(): iterable
    {
        yield 'and before or' => ['a pr and b pr or c pr', '((a pr and b pr) or c pr)'];
        yield 'and before or on the right' => ['a pr or b pr and c pr', '(a pr or (b pr and c pr))'];
        yield 'or is left-associative' => ['a pr or b pr or c pr', '((a pr or b pr) or c pr)'];
        yield 'and is left-associative' => ['a pr and b pr and c pr', '((a pr and b pr) and c pr)'];
        yield 'not before and' => ['not (a pr) and b pr', '(not(a pr) and b pr)'];
        yield 'group before and' => ['(a pr or b pr) and c pr', '((a pr or b pr) and c pr)'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function leniency(): iterable
    {
        yield 'operator in mixed case' => ['UserName Eq "bjensen"', 'UserName eq "bjensen"'];
        yield 'logical operators in any case' => ['a pr AND b pr Or c pr', '((a pr and b pr) or c pr)'];
        yield 'not without space' => ['not(title pr)', 'not(title pr)'];
        yield 'tab and newline' => ["userName\teq\n\"bjensen\"", 'userName eq "bjensen"'];
        yield 'attribute named like an operator' => ['eq eq "x"', 'eq eq "x"'];
        yield '$ref sub-attribute' => ['groups.$ref pr', 'groups.$ref pr'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function comparisonValues(): iterable
    {
        yield 'true' => ['active eq true', 'active eq true'];
        yield 'integer' => ['loginCount eq 42', 'loginCount eq 42'];
        yield 'negative integer' => ['loginCount gt -7', 'loginCount gt -7'];
        yield 'exponent is a float' => ['score eq 1e3', 'score eq 1000.0'];
        yield 'empty string' => ['title eq ""', 'title eq ""'];
        yield 'escaped quote' => ['title eq "a\"b"', 'title eq "a\"b"'];
        yield 'escaped slash and backslash' => ['title eq "a\/b\\\c"', 'title eq "a/b\\\c"'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        $enterprise = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:';

        yield 'extension attribute' => [$enterprise.'employeeNumber pr', $enterprise.'employeeNumber pr'];
        yield 'extension sub-attribute' => [$enterprise.'manager.value eq "26118915"', $enterprise.'manager.value eq "26118915"'];
        yield 'URN value path' => [self::CORE.'emails[type eq "work"]', self::CORE.'emails['.self::CORE.'emails.type eq "work"]'];
        yield 'Entra value path with comparison' => [
            'emails[type eq "work"].value eq "user@contoso.com"',
            'emails[(emails.type eq "work" and emails.value eq "user@contoso.com")]',
        ];
    }

    #[Test]
    #[DataProvider('rfc7644Figure2')]
    #[DataProvider('precedence')]
    #[DataProvider('leniency')]
    #[DataProvider('comparisonValues')]
    #[DataProvider('paths')]
    #[TestDox('Parses $filter')]
    public function parsesFilter(string $filter, string $expectedTree): void
    {
        self::assertSame($expectedTree, FilterNotation::of(new FilterParser()->parse($filter)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFilters(): iterable
    {
        $value = 'a comparison value (a JSON string, number, true, false or null)';

        yield 'empty' => ['', 'Expected an attribute path, found the end of the filter.'];
        yield 'no operator' => ['userName', "Expected an attribute operator after 'userName', found the end of the filter."];
        yield 'no value' => ['userName eq', "Expected {$value}, found the end of the filter."];
        yield 'unknown operator' => ['userName regex "J"', "The operator 'regex' at offset 9 is not supported."];
        yield 'single quotes' => ["displayName sw 'Group'", "Expected {$value} at offset 15, found ''Group''."];
        yield 'True' => ['active eq True', "Expected {$value} at offset 10, found 'True'."];
        yield 'leading zero' => ['loginCount eq 01', "'01' at offset 14 is not a valid JSON value."];
        yield 'not without parentheses' => ['not title pr', "Expected '(' at offset 4, found 'title'."];
        yield 'unclosed parenthesis' => ['(title pr', "Expected ')', found the end of the filter."];
        yield 'extra parenthesis' => ['title pr)', "Expected 'and', 'or' or the end of the filter at offset 8, found ')'."];
        yield 'and without right side' => ['title pr and', 'Expected an attribute path, found the end of the filter.'];
        yield 'or without right side' => ['userName eq "a" or', 'Expected an attribute path, found the end of the filter.'];
        yield 'two sub-attributes' => ['name.givenName.x pr', "'name.givenName.x' at offset 0 is not a valid attribute path."];
        yield 'unclosed bracket' => ['emails[type eq "work"', "Expected ']', found the end of the filter."];
        yield 'empty brackets' => ['emails[]', "Expected an attribute path at offset 7, found ']'."];
        yield 'nested value path' => ['emails[type[value eq "x"] pr]', "Value paths do not nest: found '[' after 'emails.type' at offset 11."];
        yield 'full path in brackets' => ['emails[emails.type eq "work"]', "Expected a sub-attribute name of 'emails' at offset 7, found 'emails.type'."];
        yield 'URN in brackets' => [
            'emails['.self::CORE.'type eq "work"]',
            "Expected a sub-attribute name of 'emails' at offset 7, found '".self::CORE."type'.",
        ];
        yield 'value path on a sub-attribute' => ['name.givenName[value eq "x"]', "A value path needs an attribute without a sub-attribute, found 'name.givenName[' at offset 14."];
        yield 'value path with two sub-attributes' => [
            'emails[type eq "work"].value.display eq "x"',
            "Expected an attribute operator after 'emails.value' at offset 28, found '.display'.",
        ];
        yield 'invalid UTF-8' => ["title eq \"\xFF\"", 'The filter is not valid UTF-8.'];
    }

    #[Test]
    #[DataProvider('invalidFilters')]
    #[TestDox('Rejects $filter with invalidFilter')]
    public function rejectsFilter(string $filter, string $detail): void
    {
        self::assertThrowsScimException(ScimErrorType::InvalidFilter, $detail, static fn (): mixed => new FilterParser()->parse($filter));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filtersAtTheLimits(): iterable
    {
        yield '8192 bytes' => ['title eq "'.str_repeat('a', 8181).'"', 'title eq "'.str_repeat('a', 8181).'"'];
        yield '64 nested groups' => [str_repeat('(', 64).'a pr'.str_repeat(')', 64), 'a pr'];
        yield 'a value path in 63 nested groups' => [str_repeat('(', 63).'emails[type pr]'.str_repeat(')', 63), 'emails[emails.type pr]'];
    }

    #[Test]
    #[DataProvider('filtersAtTheLimits')]
    #[TestDox('Parses a filter of $_dataName')]
    public function parsesFilterAtTheLimits(string $filter, string $expectedTree): void
    {
        self::assertSame($expectedTree, FilterNotation::of(new FilterParser()->parse($filter)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filtersAboveTheLimits(): iterable
    {
        yield '8193 bytes' => ['title eq "'.str_repeat('a', 8182).'"', 'The filter is longer than 8192 bytes.'];
        yield '8193 bytes of invalid UTF-8' => [str_repeat("\xFF", 8193), 'The filter is longer than 8192 bytes.'];
        yield '65 nested groups' => [str_repeat('(', 65).'a pr'.str_repeat(')', 65), 'The filter nests deeper than 64 levels at offset 64.'];
        yield 'a value path in 64 nested groups' => [str_repeat('(', 64).'emails[type pr]'.str_repeat(')', 64), 'The filter nests deeper than 64 levels at offset 70.'];
        yield '65 groups after 70 closed ones' => [str_repeat('(a pr)and', 70).str_repeat('(', 65), 'The filter nests deeper than 64 levels at offset 694.'];
    }

    #[Test]
    #[DataProvider('filtersAboveTheLimits')]
    #[TestDox('Rejects a filter of $_dataName with invalidFilter')]
    public function rejectsFilterAboveTheLimits(string $filter, string $detail): void
    {
        self::assertThrowsScimException(ScimErrorType::InvalidFilter, $detail, static fn (): mixed => new FilterParser()->parse($filter));
    }
}
