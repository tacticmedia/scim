<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Server\ListParameters;
use TacticMedia\ScimServer\Server\RequestParameters;

final class ListParametersTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, bool, string|null, int}>
     */
    public static function methods(): iterable
    {
        yield 'no cursor: index' => [['startIndex' => '2'], true, null, 2];
        yield 'empty cursor: first cursor page' => [['Cursor' => ''], true, '', 1];
        yield 'empty cursor and startIndex: index' => [['cursor' => '', 'startIndex' => '3'], true, null, 3];
        yield 'cursor wins over startIndex' => [['cursor' => 'abc', 'startIndex' => '3'], true, 'abc', 1];
        yield 'cursors off: cursor ignored' => [['cursor' => 'abc', 'startIndex' => '3'], false, null, 3];
        yield 'cursors off: a cursor that is not a string ignored' => [['cursor' => [1]], false, null, 1];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[Test]
    #[DataProvider('methods')]
    #[TestDox('Pagination method: $_dataName')]
    public function selectsMethod(array $parameters, bool $cursors, ?string $cursor, int $startIndex): void
    {
        $list = ListParameters::from(new RequestParameters($parameters), 10, $cursors);

        self::assertSame([$cursor, $startIndex], [$list->cursor, $list->startIndex]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ScimException}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'a cursor-mode count that is not an integer' => [['cursor' => '', 'count' => '1.5'], ScimException::invalidCount('The parameter "count" must be an integer.')];
        yield 'an index-mode count that is not an integer' => [['count' => '1.5'], ScimException::invalidValue('The parameter "count" must be an integer.')];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[Test]
    #[DataProvider('invalidParameters')]
    #[TestDox('Rejects $_dataName')]
    public function rejectsInvalidParameters(array $parameters, ScimException $expected): void
    {
        try {
            ListParameters::from(new RequestParameters($parameters), 10, true);
            self::fail('No exception.');
        } catch (ScimException $scimException) {
            self::assertSame($expected->toArray(), $scimException->toArray());
        }
    }
}
