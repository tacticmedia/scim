<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;

/**
 * For WebTestCase tests: requests through the firewall of the test kernel to /scim/v2, on an empty database. The
 * kernel is not rebooted between requests, so the in-memory database persists.
 */
trait ScimClient
{
    use CreatesSchema;

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-06 10:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::createSchema();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    /**
     * Request::create() gives a POST or PUT without Content-Type the type application/x-www-form-urlencoded, so a
     * body always has a Content-Type: application/scim+json unless $headers sets another one.
     *
     * @param array<array-key, mixed>|null $body    sent as JSON
     * @param array<string, string>        $headers HTTP headers, by name
     */
    private function send(string $method, string $uri, ?array $body = null, array $headers = Scim::ENTRA): Response
    {
        $server = [];

        foreach ([...(null === $body ? [] : ['Content-Type' => 'application/scim+json']), ...$headers] as $name => $value) {
            $server['Content-Type' === $name ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(strtr($name, '-', '_'))] = $value;
        }

        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> ...$operations
     *
     * @return array<string, mixed>
     */
    private static function patchOp(array ...$operations): array
    {
        return ['schemas' => [Scim::PATCH_OP], 'Operations' => $operations];
    }

    private static function idOf(Response $response): string
    {
        $id = self::json($response)['id'] ?? null;
        self::assertIsString($id, (string) $response->getContent());

        return $id;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function resources(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $resources = self::json($response)['Resources'] ?? null;
        self::assertIsArray($resources);
        self::assertContainsOnlyArray($resources);

        return array_values($resources);
    }

    /**
     * @return list<mixed>
     */
    private static function ids(Response $response): array
    {
        return array_column(self::resources($response), 'id');
    }

    private static function assertNoContent(Response $response): void
    {
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('', $response->getContent());
    }

    private static function assertScimError(Response $response, int $status, ?string $scimType, string $detail): void
    {
        self::assertSame('application/scim+json', $response->headers->get('Content-Type'));
        self::assertSame(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => (string) $status, ...(null === $scimType ? [] : ['scimType' => $scimType]), 'detail' => $detail], self::json($response));
        self::assertSame($status, $response->getStatusCode());
    }
}
