<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

/**
 * RFC 9967 section 2.5.1.1 lets a server answer Prefer: respond-async with a normal SCIM response.
 */
final class RespondAsyncTest extends KernelTestCase
{
    use ServerFixture;

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null, int}>
     */
    public static function writes(): iterable
    {
        yield 'POST' => ['POST', '/Users', ['userName' => 'jsmith'], 201];
        yield 'PUT' => ['PUT', '/Users/u1', ['userName' => 'babs'], 200];
        yield 'PATCH' => ['PATCH', '/Users/u1', ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'add', 'path' => 'nickName', 'value' => 'Babs']]], 204];
        yield 'DELETE' => ['DELETE', '/Users/u1', null, 204];
        yield 'Bulk' => ['POST', '/Bulk', ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:BulkRequest'], 'Operations' => [['method' => 'DELETE', 'path' => '/Users/u1']]], 200];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('writes')]
    #[TestDox('$_dataName with Prefer: respond-async answers synchronously')]
    public function answersSynchronously(string $method, string $path, ?array $body, int $status): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        $response = $this->send($server, $method, $path, $body, ['Prefer' => 'respond-async, wait=10']);

        self::assertSame($status, $response->getStatusCode());
        self::assertFalse($response->headers->has('Preference-Applied'));
        self::assertFalse($response->headers->has('Set-Txn'));
    }
}
