<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\FilterFolder;

final class FilterFolderTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, bool, string|bool}>
     */
    public static function filters(): iterable
    {
        yield 'a defined attribute stays' => ['displayName eq "x"', false, 'displayName eq "x"'];
        yield 'eq on an undefined attribute across types' => ['userName eq "x"', true, false];
        yield 'meta.resourceType eq the type name' => ['META.RESOURCETYPE eq "Group"', false, true];
        yield 'meta.resourceType is case-exact' => ['meta.resourceType eq "group"', false, false];
        yield 'and with false on the left' => ['userName pr and displayName pr', true, false];
        yield 'and with false on the right' => ['displayName pr and userName pr', true, false];
        yield 'and with true on the left' => ['userName ne "x" and displayName pr', true, 'displayName pr'];
        yield 'and with true on the right' => ['displayName pr and userName ne "x"', true, 'displayName pr'];
        yield 'or with true on the left' => ['userName ne "x" or displayName pr', true, true];
        yield 'or with true on the right' => ['displayName pr or userName ne "x"', true, true];
        yield 'or with false on the left' => ['userName pr or displayName pr', true, 'displayName pr'];
        yield 'or with false on the right' => ['displayName pr or userName pr', true, 'displayName pr'];
        yield 'not of a result' => ['not (userName pr)', true, true];
        yield 'not of the rest stays' => ['not (displayName pr)', true, 'not (displayName pr)'];
        yield 'and and or of the rest stay' => ['displayName pr and (externalId pr or id pr)', true, 'displayName pr and (externalId pr or id pr)'];
        yield 'a meta value path with the type name' => ['meta[resourceType eq "Group"]', false, true];
        yield 'a meta value path with another name' => ['meta[resourceType eq "User"]', false, false];
        yield 'a meta value path keeps the other sub-attributes' => ['meta[resourceType eq "Group" and created pr]', false, 'meta[created pr]'];
    }

    #[Test]
    #[DataProvider('filters')]
    #[TestDox('Folds for Group: $_dataName')]
    public function folds(string $filter, bool $acrossTypes, string|bool $expected): void
    {
        $parser = new FilterParser();

        $folded = new FilterFolder()->fold($parser->parse($filter), CoreResourceTypes::group(), $acrossTypes);

        self::assertEquals(\is_bool($expected) ? $expected : $parser->parse($expected), $folded);
    }

    #[Test]
    #[TestDox('Keeps a filter node that it does not know')]
    public function keepsUnknownNode(): void
    {
        $unknown = new class implements Filter {
        };

        self::assertSame($unknown, new FilterFolder()->fold($unknown, CoreResourceTypes::group(), true));
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function invalidFilters(): iterable
    {
        yield 'a meta.resourceType comparison' => ['meta.resourceType gt null', false, "The operator 'gt' does not accept null."];
        yield 'an undefined attribute for one type' => ['meta.resourceType eq "Group" or userName eq "x"', false, "The attribute 'userName' is not defined for the resource type Group."];
        yield 'a comparison that the fold drops' => ['meta.resourceType eq "User" and displayName gt true', true, "The value true does not fit the string attribute 'urn:ietf:params:scim:schemas:core:2.0:Group:displayName'."];
        yield 'an undefined sub-attribute of meta' => ['meta[resourceType eq "Group" or foo eq "x"]', true, "The attribute 'meta.foo' is not defined for the resource type Group."];
    }

    #[Test]
    #[DataProvider('invalidFilters')]
    #[TestDox('Throws invalidFilter for $_dataName, also when the type decides the result')]
    public function rejectsInvalidLeaf(string $filter, bool $acrossTypes, string $detail): void
    {
        $this->expectExceptionObject(ScimException::invalidFilter($detail));

        new FilterFolder()->fold(new FilterParser()->parse($filter), CoreResourceTypes::group(), $acrossTypes);
    }
}
