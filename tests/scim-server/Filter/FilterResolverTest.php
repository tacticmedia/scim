<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Filter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Filter\Present;
use TacticMedia\ScimServer\Filter\ValuePathFilter;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;

final class FilterResolverTest extends KernelTestCase
{
    use AssertsScimException;
    private const string U = UserResourceType::CORE;
    private const string E = UserResourceType::ENTERPRISE;
    private const string T = UserResourceType::TEST;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function resolutions(): iterable
    {
        yield 'attribute name case' => ['USERNAME eq "x"', self::U.'userName eq "x"'];
        yield 'URN case' => ['URN:IETF:PARAMS:SCIM:SCHEMAS:CORE:2.0:USER:userName pr', self::U.'userName pr'];
        yield 'sub-attribute case' => ['name.GIVENNAME eq "Barbara"', self::U.'name.givenName eq "Barbara"'];
        yield 'multi-valued complex comparison targets value' => ['emails co "example.com"', self::U.'emails.value co "example.com"'];
        yield 'singular complex comparison targets value (Entra manager)' => ['manager eq "x"', self::E.'manager.value eq "x"'];
        yield 'singular complex pr' => ['name pr', self::U.'name pr'];
        yield 'unqualified extension attribute' => ['employeeNumber eq "701984"', self::E.'employeeNumber eq "701984"'];
        yield 'value path' => ['emails[TYPE eq "work"]', self::U.'emails['.self::U.'emails.type eq "work"]'];
        yield 'Entra value path' => [
            'emails[type eq "work"].value eq "a@example.com"',
            self::U.'emails[('.self::U.'emails.type eq "work" and '.self::U.'emails.value eq "a@example.com")]',
        ];
        yield 'Entra boolean string in any case' => ['active eq "fAlSe"', self::U.'active eq false'];
        yield 'dateTime' => ['meta.lastModified gt "2011-05-13T04:42:34Z"', self::U.'meta.lastModified gt "2011-05-13T04:42:34Z"'];
        yield 'integer' => ['loginCount ge 5', self::T.'loginCount ge 5'];
        yield 'decimal with integer' => ['score lt 5', self::T.'score lt 5'];
        yield 'decimal with float' => ['score lt 4.5', self::T.'score lt 4.5'];
        yield 'binary eq' => ['avatar eq "YQ=="', self::T.'avatar eq "YQ=="'];
        yield 'eq null' => ['title eq null', self::U.'title eq null'];
        yield 'logical operators' => [
            'not (title pr) and (userType eq "a" or nickName pr)',
            '(not('.self::U.'title pr) and ('.self::U.'userType eq "a" or '.self::U.'nickName pr))',
        ];
    }

    #[Test]
    #[DataProvider('resolutions')]
    #[TestDox('Resolves $filter')]
    public function resolvesFilter(string $filter, string $expectedTree): void
    {
        $resolved = new FilterResolver()->resolve(new FilterParser()->parse($filter), UserResourceType::create());

        self::assertSame($expectedTree, FilterNotation::of($resolved));
    }

    /**
     * @return iterable<string, array{string|Filter, string}>
     */
    public static function invalidFilters(): iterable
    {
        yield from self::unknownAttributes();
        yield from self::operatorsThatDoNotApply();
        yield from self::valuesThatDoNotFit();
        yield from self::attributesThatAreNeverReturned();
        yield from self::malformedTrees();
    }

    /**
     * @return iterable<string, array{string|Filter, string}>
     */
    private static function unknownAttributes(): iterable
    {
        yield 'unknown attribute' => ['foo pr', "The attribute 'foo' is not defined for the resource type User."];
        yield 'unknown sub-attribute' => ['name.foo pr', "The attribute 'name.foo' is not defined for the resource type User."];
        yield 'unknown schema' => ['urn:example:unknown:foo pr', "The attribute 'urn:example:unknown:foo' is not defined for the resource type User."];
    }

    /**
     * @return iterable<string, array{string|Filter, string}>
     */
    private static function operatorsThatDoNotApply(): iterable
    {
        yield 'eq on singular complex' => ['name eq "x"', "'".self::U."name' is complex. Compare one of its sub-attributes."];

        foreach ([
            'gt on boolean' => ['active gt true', 'gt', 'boolean', self::U.'active'],
            'ge on binary' => ['avatar ge "x"', 'ge', 'binary', self::T.'avatar'],
            'co on dateTime' => ['meta.lastModified co "2011"', 'co', 'dateTime', self::U.'meta.lastModified'],
            'co on binary' => ['avatar co "Y"', 'co', 'binary', self::T.'avatar'],
        ] as $name => [$filter, $operator, $type, $path]) {
            yield $name => [$filter, "The operator '{$operator}' does not apply to the {$type} attribute '{$path}'."];
        }

        yield 'gt null' => ['title gt null', "The operator 'gt' does not accept null."];
    }

    /**
     * @return iterable<string, array{string|Filter, string}>
     */
    private static function valuesThatDoNotFit(): iterable
    {
        foreach ([
            'number for string' => ['userName eq 1', '1', 'string', self::U.'userName'],
            'float for integer' => ['loginCount eq 1.5', '1.5', 'integer', self::T.'loginCount'],
            'string for integer' => ['loginCount eq "1"', "'1'", 'integer', self::T.'loginCount'],
            'string for decimal' => ['score eq "1.5"', "'1.5'", 'decimal', self::T.'score'],
            'yes for boolean' => ['active eq "yes"', "'yes'", 'boolean', self::U.'active'],
            'number for boolean' => ['active eq 1', '1', 'boolean', self::U.'active'],
            'relative dateTime' => ['meta.lastModified gt "yesterday"', "'yesterday'", 'dateTime', self::U.'meta.lastModified'],
        ] as $name => [$filter, $value, $type, $path]) {
            yield $name => [$filter, "The value {$value} does not fit the {$type} attribute '{$path}'."];
        }
    }

    /**
     * @return iterable<string, array{string|Filter, string}>
     */
    private static function attributesThatAreNeverReturned(): iterable
    {
        foreach ([
            'sw on password next to a filter that matches' => ['userName eq "victim" and password sw "h"', self::U.'password'],
            'ne on a writeOnly attribute' => ['pin ne "1234"', self::T.'pin'],
            'a sub-attribute of an attribute that is never returned' => ['recoveryCodes.type eq "x"', self::T.'recoveryCodes.type'],
            'a value path on an attribute that is never returned' => ['recoveryCodes[type eq "x"]', self::T.'recoveryCodes.type'],
        ] as $name => [$filter, $path]) {
            yield $name => [$filter, "The attribute '{$path}' is never returned and cannot be used in a filter."];
        }
    }

    /**
     * @return iterable<string, array{string|Filter, string}>
     */
    private static function malformedTrees(): iterable
    {
        $emails = new AttributePath(null, 'emails');
        $emailsType = new AttributePath(null, 'emails', 'type');
        $workEmail = new Comparison($emailsType, ComparisonOperator::Equal, 'work');

        yield 'value path on a simple attribute' => ['userName[value eq "x"]', "'userName[...]' needs a complex attribute, and '".self::U."userName' is not one."];
        yield 'value path on a sub-attribute' => [
            new ValuePathFilter(new AttributePath(null, 'name', 'givenName'), new Present(new AttributePath(null, 'name', 'familyName'))),
            "'name.givenName[...]' needs a complex attribute, and '".self::U."name.givenName' is not one.",
        ];
        yield 'nested value path' => [
            new ValuePathFilter($emails, new ValuePathFilter($emails, $workEmail)),
            "Value paths do not nest: 'emails[...]' is inside '".self::U."emails[...]'.",
        ];
        yield 'value path filter outside its attribute' => [
            new ValuePathFilter($emails, new Present(new AttributePath(null, 'userName'))),
            "'userName' is not a sub-attribute of '".self::U."emails'.",
        ];
        yield 'value path filter on another complex attribute' => [
            new ValuePathFilter($emails, new Present(new AttributePath(null, 'ims', 'type'))),
            "'ims.type' is not a sub-attribute of '".self::U."emails'.",
        ];
        yield 'unknown node' => [new UnknownFilter(), 'The filter node '.UnknownFilter::class.' is not supported.'];
    }

    #[Test]
    #[DataProvider('invalidFilters')]
    #[TestDox('Rejects $_dataName with invalidFilter')]
    public function rejectsFilter(string|Filter $filter, string $detail): void
    {
        $filter = \is_string($filter) ? new FilterParser()->parse($filter) : $filter;

        self::assertThrowsScimException(ScimErrorType::InvalidFilter, $detail, static fn (): mixed => new FilterResolver()->resolve($filter, UserResourceType::create()));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function sortAttributes(): iterable
    {
        yield 'sub-attribute in any case' => ['NAME.GIVENNAME', self::U.'name.givenName'];
        yield 'multi-valued complex sorts by value' => ['emails', self::U.'emails.value'];
        yield 'complex without value stays' => ['name', self::U.'name'];
        yield 'unknown attribute' => ['shoeSize', null];
    }

    #[Test]
    #[DataProvider('sortAttributes')]
    #[TestDox('resolveSortBy(): $_dataName')]
    public function resolvesSortBy(string $sortBy, ?string $expectedPath): void
    {
        $resolved = new FilterResolver()->resolveSortBy(self::path($sortBy), UserResourceType::create());

        self::assertSame($expectedPath, $resolved instanceof ResolvedAttribute ? (string) $resolved->path() : null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sortAttributesThatAreNeverReturned(): iterable
    {
        yield 'password' => ['password', self::U.'password'];
        yield 'a writeOnly attribute' => ['pin', self::T.'pin'];
        yield 'a sub-attribute of an attribute that is never returned' => ['recoveryCodes.type', self::T.'recoveryCodes.type'];
    }

    #[Test]
    #[DataProvider('sortAttributesThatAreNeverReturned')]
    #[TestDox('resolveSortBy() rejects $_dataName with invalidValue')]
    public function rejectsSortBy(string $sortBy, string $path): void
    {
        self::assertThrowsScimException(
            ScimErrorType::InvalidValue,
            "The sortBy attribute \"{$path}\" is never returned and cannot be used to sort.",
            static fn (): mixed => new FilterResolver()->resolveSortBy(self::path($sortBy), UserResourceType::create()),
        );
    }

    private static function path(string $path): AttributePath
    {
        return AttributePath::parse($path) ?? throw new \LogicException(\sprintf('"%s" is not an attribute path.', $path));
    }
}
