<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Resource;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Resource\ValueEquality;

final class ValueEqualityTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{mixed, mixed, bool}>
     */
    public static function pairs(): iterable
    {
        yield 'null and null' => [null, null, true];
        yield 'strings in other case' => ['a', 'A', false];
        yield 'numeric string and integer' => ['1', 1, false];
        yield 'integer and float' => [5, 5.0, false];
        yield 'objects in other key order' => [['a' => 1, 'b' => ['c' => 2, 'd' => 3]], ['b' => ['d' => 3, 'c' => 2], 'a' => 1], true];
        yield 'lists of objects in other key order' => [[['a' => 1, 'b' => 2]], [['b' => 2, 'a' => 1]], true];
        yield 'lists in other order' => [['a', 'b'], ['b', 'a'], false];
    }

    #[Test]
    #[DataProvider('pairs')]
    #[TestDox('same() compares exactly and ignores the key order of objects')]
    public function comparesExactlyAndIgnoresTheKeyOrderOfObjects(mixed $left, mixed $right, bool $expected): void
    {
        self::assertSame($expected, ValueEquality::same($left, $right));
    }
}
