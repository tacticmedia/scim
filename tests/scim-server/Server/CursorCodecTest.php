<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Server\CursorCodec;

final class CursorCodecTest extends KernelTestCase
{
    private const string KEY = 'cursor-key';

    #[Test]
    #[TestDox('Decodes an offset and a repository position')]
    public function roundTrips(): void
    {
        $codec = new CursorCodec(self::KEY);

        self::assertSame(7, $codec->decode($codec->encode('q', 7), 'q'));
        self::assertSame(['Jensen', 1.0, true, null, 3], $codec->decode($codec->encode('q', ['Jensen', 1.0, true, null, 3]), 'q'));
    }

    #[Test]
    #[TestDox('Each cursor is a new base64url string without padding')]
    public function encodesUniqueUrlSafeCursors(): void
    {
        $codec = new CursorCodec(self::KEY);

        $first = $codec->encode('q', 7);

        self::assertNotSame($first, $codec->encode('q', 7));
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $first);
    }

    /**
     * @return iterable<string, array{\Closure(string): string, string}>
     */
    public static function invalidCursors(): iterable
    {
        yield 'one changed character' => [static fn (string $cursor): string => ('A' === $cursor[0] ? 'B' : 'A').substr($cursor, 1), 'q'];
        yield 'another key' => [static fn (): string => new CursorCodec('other-key')->encode('q', 7), 'q'];
        yield 'another query' => [static fn (string $cursor): string => $cursor, 'other query'];
        yield 'a character outside base64url' => [static fn (string $cursor): string => $cursor.'=', 'q'];
        yield 'shorter than a nonce' => [static fn (): string => 'abc', 'q'];
        yield 'a payload that is not JSON' => [static fn (): string => self::sealed('{'), 'q'];
        yield 'a payload without an issue time' => [static fn (): string => self::sealed('{"q":"q","p":7}'), 'q'];
        yield 'a negative offset' => [static fn (): string => self::sealed('{"q":"q","t":0,"p":-1}'), 'q'];
        yield 'a position that is not a list' => [static fn (): string => self::sealed('{"q":"q","t":0,"p":{"a":1}}'), 'q'];
        yield 'a position with an object' => [static fn (): string => self::sealed('{"q":"q","t":0,"p":[{"a":1}]}'), 'q'];
    }

    /**
     * @param \Closure(string): string $tamper
     */
    #[Test]
    #[DataProvider('invalidCursors')]
    #[TestDox('Answers 400 invalidCursor with one fixed detail for $_dataName')]
    public function rejectsInvalidCursor(\Closure $tamper, string $query): void
    {
        $codec = new CursorCodec(self::KEY);

        try {
            $codec->decode($tamper($codec->encode('q', 7)), $query);
            self::fail('No exception.');
        } catch (ScimException $scimException) {
            self::assertSame([400, 'invalidCursor', 'The cursor is not valid for this query.'], [$scimException->status, $scimException->scimType?->value, $scimException->detail]);
        }
    }

    #[Test]
    #[TestDox('With a lifetime, a cursor is valid for exactly that many seconds, then 400 expiredCursor')]
    public function expires(): void
    {
        $clock = new MockClock('2026-11-06T10:00:00Z');
        $codec = new CursorCodec(self::KEY, 60, $clock);
        $cursor = $codec->encode('q', 7);

        $clock->sleep(60);
        self::assertSame(7, $codec->decode($cursor, 'q'));
        $clock->sleep(1);
        $this->expectExceptionObject(ScimException::expiredCursor());
        $codec->decode($cursor, 'q');
    }

    #[Test]
    #[TestDox('Without a lifetime, a cursor does not expire')]
    public function doesNotExpireWithoutLifetime(): void
    {
        $clock = new MockClock('2026-11-06T10:00:00Z');
        $codec = new CursorCodec(self::KEY, clock: $clock);
        $cursor = $codec->encode('q', 7);

        $clock->sleep(86400 * 365);

        self::assertSame(7, $codec->decode($cursor, 'q'));
    }

    /**
     * A cursor with an arbitrary payload under self::KEY, built as CursorCodec builds one.
     */
    private static function sealed(string $payload): string
    {
        $key = hash_hkdf('sha256', self::KEY, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'tacticmedia/scim-server cursor');
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return sodium_bin2base64($nonce.sodium_crypto_secretbox($payload, $nonce, $key), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
