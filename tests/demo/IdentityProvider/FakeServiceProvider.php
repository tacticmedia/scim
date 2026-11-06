<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;

/**
 * The response factory of the MockHttpClient in the test environment: a library ScimServer at
 * http://127.0.0.1:8001/scim/v2 that accepts the token with and without "Bearer". Another host is not reachable.
 *
 * @phpstan-type RecordedRequest array{line: string, headers: array<string, string>, body: array<mixed>|null, status: int, response: array<mixed>|null}
 */
final class FakeServiceProvider
{
    public const string BASE_URL = 'http://127.0.0.1:8001/scim/v2';
    public const string TOKEN = 'demo-token';
    private const array RECORDED_HEADERS = ['accept', 'authorization', 'content-type', 'user-agent'];

    public readonly InMemoryResourceRepository $users;

    public readonly InMemoryResourceRepository $groups;

    /** @var list<RecordedRequest> */
    public private(set) array $requests = [];

    /** False: the server ignores "cursor" and pages by startIndex only. */
    public bool $cursors = true;

    /** False: /ServiceProviderConfig says that PATCH is not supported. */
    public bool $patchSupported = true;

    public function __construct()
    {
        $clock = new MockClock('2026-11-05T10:00:00Z');
        $this->users = new InMemoryResourceRepository(CoreResourceTypes::user(), $clock);
        $this->groups = new InMemoryResourceRepository(CoreResourceTypes::group(), $clock);
    }

    /**
     * @param array{normalized_headers: array<string, list<string>>, body: string} $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        if (!str_starts_with($url, self::BASE_URL)) {
            return new MockResponse('', ['error' => 'Connection refused']);
        }

        $headers = $this->headers($options['normalized_headers']);
        $request = Request::create($url, $method, content: $options['body']);
        $request->headers->add($headers);

        $response = \in_array($headers['authorization'] ?? null, [self::TOKEN, 'Bearer '.self::TOKEN], true)
            ? $this->server()->handle($request, '/scim/v2')
            : null;
        $status = $response?->getStatusCode() ?? 401;
        $content = $response instanceof \Symfony\Component\HttpFoundation\Response ? (string) $response->getContent() : '{"schemas":["urn:ietf:params:scim:api:messages:2.0:Error"],"status":"401","detail":"Authorization failure."}';

        if (!$this->patchSupported && str_ends_with($url, '/ServiceProviderConfig')) {
            $content = json_encode(['patch' => ['supported' => false]] + (array) json_decode($content, true), \JSON_THROW_ON_ERROR);
        }

        $this->requests[] = [
            'line' => $method.' '.substr($url, \strlen(self::BASE_URL)),
            'headers' => $headers,
            'body' => $this->decode($options['body']),
            'status' => $status,
            'response' => $this->decode($content),
        ];

        return new MockResponse($content, ['http_code' => $status]);
    }

    public function forgetRequests(): void
    {
        $this->requests = [];
    }

    /**
     * @return array<mixed>
     */
    public function user(?string $id): array
    {
        return $this->users->get((string) $id) ?? throw new \OutOfBoundsException(\sprintf('No user %s.', $id));
    }

    /**
     * @return array<mixed>
     */
    public function group(?string $id): array
    {
        return $this->groups->get((string) $id) ?? throw new \OutOfBoundsException(\sprintf('No group %s.', $id));
    }

    private function server(): ScimServer
    {
        return new ScimServer(
            [new RegisteredResourceType(CoreResourceTypes::user(), $this->users), new RegisteredResourceType(CoreResourceTypes::group(), $this->groups)],
            cursorKey: $this->cursors ? 'fake-service-provider-cursor-key' : null,
        );
    }

    /**
     * @param array<string, list<string>> $normalizedHeaders
     *
     * @return array<string, string>
     */
    private function headers(array $normalizedHeaders): array
    {
        $headers = [];

        foreach (array_intersect_key($normalizedHeaders, array_flip(self::RECORDED_HEADERS)) as $name => $lines) {
            $headers[$name] = substr($lines[0], \strlen($name) + 2);
        }

        ksort($headers);

        return $headers;
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $json): ?array
    {
        return '' === $json ? null : (array) json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }
}
