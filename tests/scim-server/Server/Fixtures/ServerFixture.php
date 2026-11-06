<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server\Fixtures;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Event\ResourceCreated;
use TacticMedia\ScimServer\Event\ResourceDeleted;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\MeResolver;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServer\Server\ServiceProviderConfig;

/**
 * A server with InMemoryResourceRepository instances for CoreResourceTypes::user() and ::group() under
 * https://example.com/scim/v2.
 */
trait ServerFixture
{
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    private const string ERROR = 'urn:ietf:params:scim:api:messages:2.0:Error';
    private const string BASE_URL = 'https://example.com/scim/v2';

    private InMemoryResourceRepository $users;

    private InMemoryResourceRepository $groups;

    private MockClock $clock;

    /** @var list<object> */
    private array $events = [];

    /**
     * @param list<array<string, mixed>> $users
     * @param list<array<string, mixed>> $groups
     * @param string|null                $cursorKey null: cursor pagination off
     */
    private function server(array $users = [], array $groups = [], ServiceProviderConfig $config = new ServiceProviderConfig(), ?MeResolver $me = null, ?string $cursorKey = null): ScimServer
    {
        $this->clock = new MockClock('2026-11-05T10:00:00Z');
        $this->users = new InMemoryResourceRepository(CoreResourceTypes::user(), $this->clock, $users);
        $this->groups = new InMemoryResourceRepository(CoreResourceTypes::group(), $this->clock, $groups);

        return new ScimServer([
            new RegisteredResourceType(CoreResourceTypes::user(), $this->users),
            new RegisteredResourceType(CoreResourceTypes::group(), $this->groups),
        ], $config, $this->recordingDispatcher(), $me, $cursorKey, $this->clock);
    }

    private function recordingDispatcher(): EventDispatcherInterface
    {
        $dispatcher = new EventDispatcher();

        foreach ([ResourceCreated::class, ResourceUpdated::class, ResourceDeleted::class] as $event) {
            $dispatcher->addListener($event, function (object $event): void {
                $this->events[] = $event;
            });
        }

        return $dispatcher;
    }

    /**
     * @param array<array-key, mixed>|string|null $body    an array is sent as JSON
     * @param array<string, string>               $headers Content-Type defaults to application/scim+json with a body
     */
    private function send(ScimServer $server, string $method, string $uri, array|string|null $body = null, array $headers = []): Response
    {
        $request = Request::create(self::BASE_URL.$uri, $method, content: \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body);

        if (null !== $body) {
            $request->headers->set('Content-Type', 'application/scim+json');
        }

        $request->headers->add($headers);

        return $server->handle($request, '/scim/v2');
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private static function query(array $parameters): string
    {
        return '?'.http_build_query($parameters, encoding_type: \PHP_QUERY_RFC3986);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * @return list<string>
     */
    private static function ids(Response $response): array
    {
        $resources = self::json($response)['Resources'] ?? null;
        self::assertIsArray($resources);

        return array_map(static fn (mixed $resource): string => \is_array($resource) && \is_string($resource['id'] ?? null) ? $resource['id'] : '', array_values($resources));
    }

    private static function assertScimError(Response $response, int $status, ?string $scimType, string $detail): void
    {
        $expected = ['schemas' => [self::ERROR], 'status' => (string) $status];

        if (null !== $scimType) {
            $expected['scimType'] = $scimType;
        }

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/scim+json', $response->headers->get('Content-Type'));
        self::assertSame($expected + ['detail' => $detail], self::json($response));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function storedUser(string $id, string $userName, array $extra = [], string $version = 'W/"1"'): array
    {
        return [
            'schemas' => [self::USER],
            'id' => $id,
            'meta' => ['created' => '2026-11-01T08:00:00Z', 'lastModified' => '2026-11-01T08:00:00Z', 'version' => $version],
            'userName' => $userName,
            ...$extra,
        ];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function storedGroup(string $id, string $displayName, array $extra = []): array
    {
        return [
            'schemas' => [self::GROUP],
            'id' => $id,
            'meta' => ['created' => '2026-11-01T08:00:00Z', 'lastModified' => '2026-11-01T08:00:00Z', 'version' => 'W/"1"'],
            'displayName' => $displayName,
            ...$extra,
        ];
    }
}
