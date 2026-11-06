<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Server\RequestParameters;

final class RequestParametersTest extends KernelTestCase
{
    #[Test]
    #[TestDox('Names match in any case, and "" and null count as absent')]
    public function readsStrings(): void
    {
        $parameters = new RequestParameters(['Filter' => 'title pr', 'sortBy' => '', 'count' => null]);

        self::assertSame('title pr', $parameters->string('FILTER'));
        self::assertTrue($parameters->has('filter'));
        self::assertNull($parameters->string('sortBy'));
        self::assertFalse($parameters->has('sortBy'));
        self::assertFalse($parameters->has('count'));
    }

    /**
     * @return iterable<string, array{mixed, int|null}>
     */
    public static function integers(): iterable
    {
        yield 'a JSON integer' => [5, 5];
        yield 'a query string integer' => ['10', 10];
        yield 'a signed integer with spaces' => [' -3 ', -3];
        yield 'absent' => [null, null];
    }

    #[Test]
    #[DataProvider('integers')]
    #[TestDox('Reads an integer from $_dataName')]
    public function readsIntegers(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, new RequestParameters(['count' => $value])->integer('count'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonIntegers(): iterable
    {
        yield 'a fraction' => ['1.5'];
        yield 'a JSON number with a fraction' => [1.5];
        yield 'a word' => ['ten'];
        yield 'a list' => [['1']];
    }

    #[Test]
    #[DataProvider('nonIntegers')]
    #[TestDox('Rejects an integer that is $_dataName')]
    public function rejectsNonIntegers(mixed $value): void
    {
        $this->expectExceptionObject(ScimException::invalidValue('The parameter "count" must be an integer.'));

        new RequestParameters(['count' => $value])->integer('count');
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function paths(): iterable
    {
        yield 'a comma-separated string' => ['userName, name.givenName', ['userName', 'name.givenName']];
        yield 'a list of strings' => [['emails.value', 'urn:ietf:params:scim:schemas:core:2.0:User:title'], ['emails.value', 'urn:ietf:params:scim:schemas:core:2.0:User:title']];
        yield 'items that are not paths left out' => [['userName', 'a.b.c', 7, ''], ['userName']];
        yield 'absent' => [null, []];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('paths')]
    #[TestDox('Reads attribute paths from $_dataName')]
    public function readsPaths(mixed $value, array $expected): void
    {
        $paths = new RequestParameters(['attributes' => $value])->paths('attributes');

        self::assertSame($expected, array_map(static fn (AttributePath $path): string => (string) $path, $paths));
    }

    /**
     * @return iterable<string, array{string, \Closure(RequestParameters): mixed, string}>
     */
    public static function invalidTypes(): iterable
    {
        yield 'a string that is a list' => ['filter', static fn (RequestParameters $parameters): ?string => $parameters->string('filter'), 'a string'];
        yield 'paths that are a number' => ['attributes', static fn (RequestParameters $parameters): array => $parameters->paths('attributes'), 'a comma-separated string or a list of strings'];
    }

    /**
     * @param \Closure(RequestParameters): mixed $read
     */
    #[Test]
    #[DataProvider('invalidTypes')]
    #[TestDox('Rejects $_dataName')]
    public function rejectsInvalidTypes(string $name, \Closure $read, string $expected): void
    {
        $this->expectExceptionObject(ScimException::invalidValue(\sprintf('The parameter "%s" must be %s.', $name, $expected)));

        $read(new RequestParameters(['filter' => ['x'], 'attributes' => 5]));
    }

    #[Test]
    #[TestDox('cursor() keeps "" (RFC 9865: the first page), and a cursor that is not a string is 400 invalidCursor')]
    public function readsCursor(): void
    {
        self::assertSame(['', 'abc', null], [new RequestParameters(['Cursor' => ''])->cursor(), new RequestParameters(['cursor' => 'abc'])->cursor(), new RequestParameters([])->cursor()]);
        $this->expectExceptionObject(ScimException::invalidCursor());

        new RequestParameters(['cursor' => ['abc']])->cursor();
    }
}
