<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Filter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\ValueComparator;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;

final class ValueComparatorTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{mixed, mixed, AttributeDefinition, bool}>
     */
    public static function equalities(): iterable
    {
        $caseIgnored = new AttributeDefinition('userName');
        $caseExact = new AttributeDefinition('id', caseExact: true);

        yield 'string, case ignored' => ['BJensen', 'bjensen', $caseIgnored, true];
        yield 'string, case exact' => ['BJensen', 'bjensen', $caseExact, false];
        yield 'string, full case folding' => ['STRASSE', "stra\u{00DF}e", $caseIgnored, true];
        yield 'string, NFC' => ["e\u{0301}", "\u{00E9}", $caseExact, true];
        yield 'string, different' => ['bjensen', 'jsmith', $caseIgnored, false];
        yield 'string, document value is a number' => [1, '1', $caseIgnored, false];
        yield 'binary, exact' => ['YQ==', 'YQ==', new AttributeDefinition('avatar', AttributeType::Binary), true];
        yield 'binary, case differs' => ['YQ==', 'yq==', new AttributeDefinition('avatar', AttributeType::Binary), false];
        yield 'binary, not a string' => [1, 'YQ==', new AttributeDefinition('avatar', AttributeType::Binary), false];
        yield 'boolean, Entra string in the filter' => [false, 'False', new AttributeDefinition('active', AttributeType::Boolean), true];
        yield 'boolean, different' => [true, false, new AttributeDefinition('active', AttributeType::Boolean), false];
        yield 'boolean, number' => [1, true, new AttributeDefinition('active', AttributeType::Boolean), false];
        yield 'integer and float' => [5, 5.0, new AttributeDefinition('count', AttributeType::Integer), true];
        yield 'integer, numeric string' => ['5', 5, new AttributeDefinition('count', AttributeType::Integer), false];
        yield 'dateTime, same instant in other zones' => ['2011-05-13T04:42:34Z', '2011-05-13T14:42:34+10:00', new AttributeDefinition('lastModified', AttributeType::DateTime), true];
        yield 'dateTime, microseconds differ' => ['2011-05-13T04:42:34.000001Z', '2011-05-13T04:42:34Z', new AttributeDefinition('lastModified', AttributeType::DateTime), false];
        yield 'dateTime, invalid document value' => ['yesterday', '2011-05-13T04:42:34Z', new AttributeDefinition('lastModified', AttributeType::DateTime), false];
        yield 'complex' => [['value' => 'a'], ['value' => 'a'], new AttributeDefinition('name', AttributeType::Complex), false];
    }

    #[Test]
    #[DataProvider('equalities')]
    #[TestDox('Equality: $_dataName')]
    public function comparesEquality(mixed $documentValue, mixed $filterValue, AttributeDefinition $definition, bool $expected): void
    {
        self::assertSame($expected, new ValueComparator()->equals($documentValue, $filterValue, $definition));
    }

    /**
     * @return iterable<string, array{mixed, mixed, AttributeDefinition, int}>
     */
    public static function orderings(): iterable
    {
        yield 'string, case ignored' => ['apple', 'Banana', new AttributeDefinition('userName'), -1];
        yield 'string, case exact' => ['apple', 'Banana', new AttributeDefinition('id', caseExact: true), 1];
        yield 'string, lexicographic' => ['10', '9', new AttributeDefinition('userName'), -1];
        yield 'string, equal after folding' => ['ABC', 'abc', new AttributeDefinition('userName'), 0];
        yield 'integer, numeric' => [10, 9, new AttributeDefinition('count', AttributeType::Integer), 1];
        yield 'dateTime, chronological' => ['2011-05-13T14:42:34+10:00', '2011-05-13T05:00:00Z', new AttributeDefinition('lastModified', AttributeType::DateTime), -1];
        yield 'boolean, false first' => [false, true, new AttributeDefinition('active', AttributeType::Boolean), -1];
    }

    #[Test]
    #[DataProvider('orderings')]
    #[TestDox('Ordering: $_dataName')]
    public function comparesOrder(mixed $left, mixed $right, AttributeDefinition $definition, int $expected): void
    {
        self::assertSame($expected, new ValueComparator()->compare($left, $right, $definition));
    }

    #[Test]
    #[TestDox('Ordering rejects a value that does not fit the attribute type')]
    public function orderingRejectsValueThatDoesNotFit(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException("string does not fit the integer attribute 'count'."));

        new ValueComparator()->compare('ten', 9, new AttributeDefinition('count', AttributeType::Integer));
    }

    /**
     * @return iterable<string, array{mixed, string, ComparisonOperator, AttributeDefinition, bool}>
     */
    public static function substrings(): iterable
    {
        $caseIgnored = new AttributeDefinition('userName');
        $caseExact = new AttributeDefinition('id', caseExact: true);

        yield 'co, case ignored' => ['BJensen', 'JENS', ComparisonOperator::Contains, $caseIgnored, true];
        yield 'co, case exact' => ['BJensen', 'JENS', ComparisonOperator::Contains, $caseExact, false];
        yield 'co, absent' => ['BJensen', 'smith', ComparisonOperator::Contains, $caseIgnored, false];
        yield 'sw' => ['BJensen', 'bj', ComparisonOperator::StartsWith, $caseIgnored, true];
        yield 'sw, not at start' => ['BJensen', 'jensen', ComparisonOperator::StartsWith, $caseIgnored, false];
        yield 'ew' => ['BJensen', 'SEN', ComparisonOperator::EndsWith, $caseIgnored, true];
        yield 'ew, not at end' => ['BJensen', 'bj', ComparisonOperator::EndsWith, $caseIgnored, false];
        yield 'co, NFC' => ["Caf\u{0065}\u{0301} Nero", "caf\u{00E9}", ComparisonOperator::Contains, $caseIgnored, true];
        yield 'co, document value is not a string' => [12, '1', ComparisonOperator::Contains, $caseIgnored, false];
    }

    #[Test]
    #[DataProvider('substrings')]
    #[TestDox('Substring: $_dataName')]
    public function matchesSubstring(mixed $documentValue, string $filterValue, ComparisonOperator $operator, AttributeDefinition $definition, bool $expected): void
    {
        self::assertSame($expected, new ValueComparator()->matchesSubstring($documentValue, $filterValue, $operator, $definition));
    }

    #[Test]
    #[TestDox('Substring matching rejects an operator other than co, sw and ew')]
    public function substringMatchingRejectsOtherOperator(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException("The operator 'eq' is not a substring operator."));

        new ValueComparator()->matchesSubstring('a', 'a', ComparisonOperator::Equal, new AttributeDefinition('userName'));
    }
}
