<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Server\ETag;

final class ETagTest extends KernelTestCase
{
    #[Test]
    #[TestDox('of() is meta.version when it is a non-empty string, else a weak ETag that changes with the document')]
    public function computesETag(): void
    {
        self::assertSame('W/"7"', ETag::of(['id' => 'u1', 'meta' => ['version' => 'W/"7"']]));
        self::assertSame(ETag::of(['id' => 'u1', 'meta' => ['version' => '']]), ETag::of(['id' => 'u1', 'meta' => ['version' => '']]));
        self::assertNotSame(ETag::of(['id' => 'u1', 'meta' => 'x']), ETag::of(['id' => 'u2', 'meta' => 'x']));
        self::assertMatchesRegularExpression('/\AW\/"[0-9a-f]{32}"\z/', ETag::of(['id' => 'u1']));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function comparisons(): iterable
    {
        yield 'equal weak ETags' => ['W/"a1"', 'W/"a1"', true];
        yield 'a strong header ETag against a weak one' => ['"a1"', 'W/"a1"', true];
        yield 'a weak header ETag against a strong one' => ['W/"a1"', '"a1"', true];
        yield 'one of a list' => ['W/"a0" , W/"a1"', 'W/"a1"', true];
        yield 'an ETag with a comma' => ['"a,1"', '"a,1"', true];
        yield 'any ETag' => ['*', 'W/"a1"', true];
        yield 'an unquoted version' => ['v1.0', 'v1.0', true];
        yield 'another ETag' => ['W/"a2"', 'W/"a1"', false];
        yield 'another case' => ['W/"A1"', 'W/"a1"', false];
        yield 'an empty header' => ['', 'W/"a1"', false];
    }

    #[Test]
    #[DataProvider('comparisons')]
    #[TestDox('matches() with weak comparison: $_dataName')]
    public function matchesWeakly(string $header, string $etag, bool $matches): void
    {
        self::assertSame($matches, ETag::matches($header, $etag));
    }
}
