<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Filter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Filter\ValueCoercion;

final class ValueCoercionTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{mixed, bool|null}>
     */
    public static function booleans(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'True' => ['True', true];
        yield 'FALSE' => ['FALSE', false];
        yield 'yes' => ['yes', null];
        yield 'one' => [1, null];
        yield 'null' => [null, null];
    }

    #[Test]
    #[DataProvider('booleans')]
    #[TestDox('Reads a boolean from $value')]
    public function readsBoolean(mixed $value, ?bool $expected): void
    {
        self::assertSame($expected, ValueCoercion::toBoolean($value));
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function instants(): iterable
    {
        yield 'UTC' => ['2011-05-13T04:42:34Z', '2011-05-13T04:42:34.000000+00:00'];
        yield 'offset' => ['2011-05-13T14:42:34+10:00', '2011-05-13T04:42:34.000000+00:00'];
        yield 'fraction beyond microseconds' => ['2011-05-13T04:42:34.123456789Z', '2011-05-13T04:42:34.123456+00:00'];
        yield 'no time zone is UTC' => ['2011-05-13T04:42:34', '2011-05-13T04:42:34.000000+00:00'];
        yield 'invalid day' => ['2011-02-30T00:00:00Z', null];
        yield 'invalid month' => ['2011-13-01T00:00:00Z', null];
        yield 'end of day' => ['2011-01-01T24:00:00Z', null];
        yield 'date only' => ['2011-05-13', null];
        yield 'space separator' => ['2011-05-13 04:42:34Z', null];
        yield 'lower-case z' => ['2011-05-13T04:42:34z', null];
        yield 'trailing newline' => ["2011-05-13T04:42:34Z\n", null];
        yield 'relative format' => ['tomorrow', null];
        yield 'not a string' => [1305261754, null];
    }

    #[Test]
    #[DataProvider('instants')]
    #[TestDox('Reads an xsd:dateTime instant from $value')]
    public function readsInstant(mixed $value, ?string $expectedUtc): void
    {
        self::assertSame($expectedUtc, ValueCoercion::toInstant($value)?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));
    }
}
