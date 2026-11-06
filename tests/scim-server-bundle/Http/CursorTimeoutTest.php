<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Environment cursor_timeout: pagination.cursor.timeout_seconds 60.
 */
final class CursorTimeoutTest extends WebTestCase
{
    use ScimClient;

    #[Test]
    #[TestDox('A cursor works for 60 seconds on the clock service, then it is 400 expiredCursor')]
    public function cursorExpires(): void
    {
        foreach (['u1', 'u2', 'u3'] as $userName) {
            $this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => $userName]);
        }

        $cursor = self::json($this->send('GET', '/scim/v2/Users?cursor&count=2'))['nextCursor'] ?? null;
        self::assertIsString($cursor);
        Clock::get()->sleep(60);
        $inTime = $this->send('GET', '/scim/v2/Users?count=2&cursor='.$cursor);
        Clock::get()->sleep(1);

        self::assertSame(1, self::json($inTime)['itemsPerPage'] ?? null);
        self::assertScimError($this->send('GET', '/scim/v2/Users?count=2&cursor='.$cursor), 400, 'expiredCursor', 'The cursor has expired. Request the first page again.');
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'cursor_timeout'] + $options);
    }
}
