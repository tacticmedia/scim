<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Patch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Filter\ValueComparator;
use TacticMedia\ScimServer\Patch\CanonicalValue;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;

final class CanonicalValueTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{AttributeDefinition, mixed, mixed}>
     */
    public static function pairs(): iterable
    {
        $caseIgnored = new AttributeDefinition('s');
        $caseExact = new AttributeDefinition('s', caseExact: true);
        $boolean = new AttributeDefinition('b', AttributeType::Boolean);
        $integer = new AttributeDefinition('i', AttributeType::Integer);
        $decimal = new AttributeDefinition('d', AttributeType::Decimal);
        $dateTime = new AttributeDefinition('t', AttributeType::DateTime);
        $binary = new AttributeDefinition('x', AttributeType::Binary);

        yield 'string in another case' => [$caseIgnored, 'Babs', 'bABS'];
        yield 'string in NFD and NFC' => [$caseIgnored, "e\u{301}", "\u{E9}"];
        yield 'caseExact string in another case' => [$caseExact, 'Babs', 'babs'];
        yield 'caseExact string in NFD and NFC' => [$caseExact, "e\u{301}", "\u{E9}"];
        yield 'string and number' => [$caseIgnored, '1', 1];
        yield 'boolean and Entra string' => [$boolean, true, 'True'];
        yield 'true and false' => [$boolean, true, false];
        yield 'boolean and other string' => [$boolean, true, 'yes'];
        yield 'integer and integral float' => [$integer, 1, 1.0];
        yield 'zero and negative zero' => [$decimal, 0, -0.0];
        yield 'decimals' => [$decimal, 0.1 + 0.2, 0.3];
        yield 'equal decimals' => [$decimal, 2.5, 2.5];
        yield 'float beyond the integer range' => [$decimal, 1.0e19, 1.0e19];
        yield 'number and numeric string' => [$integer, 1, '1'];
        yield 'instants in different zones' => [$dateTime, '2011-08-01T18:29:49.000Z', '2011-08-01T20:29:49+02:00'];
        yield 'instants a microsecond apart' => [$dateTime, '2011-08-01T18:29:49.000001Z', '2011-08-01T18:29:49Z'];
        yield 'instant before 1970' => [$dateTime, '1969-12-31T23:59:59.5Z', '1969-12-31T23:59:59.500Z'];
        yield 'invalid dateTime' => [$dateTime, '2011-02-30T00:00:00Z', '2011-02-30T00:00:00Z'];
        yield 'binary in another case' => [$binary, 'YQ==', 'yq=='];
        yield 'equal binary' => [$binary, 'YQ==', 'YQ=='];
        yield 'binary and number' => [$binary, 1, 1];
        yield 'complex values' => [new AttributeDefinition('c', AttributeType::Complex), ['a' => 'b'], ['a' => 'b']];
    }

    #[Test]
    #[DataProvider('pairs')]
    #[TestDox('Gives two values the same form exactly when ValueComparator::equals() is true')]
    public function matchesValueComparator(AttributeDefinition $definition, mixed $left, mixed $right): void
    {
        $leftForm = CanonicalValue::scalar($left, $definition);

        self::assertSame(
            new ValueComparator()->equals($left, $right, $definition),
            null !== $leftForm && $leftForm === CanonicalValue::scalar($right, $definition),
        );
    }

    #[Test]
    #[TestDox('Keeps the sub-attributes in schema spelling, in name order')]
    public function keepsSchemaSubAttributes(): void
    {
        $emails = new AttributeDefinition('emails', AttributeType::Complex, multiValued: true, subAttributes: [
            new AttributeDefinition('value'),
            new AttributeDefinition('type'),
            new AttributeDefinition('primary', AttributeType::Boolean),
        ]);

        self::assertSame(
            ['primary' => 'true', 'type' => null, 'value' => 'babs@example.com'],
            new CanonicalValue($emails)->of(['value' => 'Babs@example.com', 'Type' => 'work', 'type' => 1, 'primary' => true, 'display' => 'x', 'foo' => 'y', 0 => 'z', 'value2' => null]),
        );
        self::assertNull(new CanonicalValue($emails)->of('babs@example.com'));
    }
}
