<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Conformance\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

/**
 * Sends requests with only the headers that the IdP sends: self::HEADERS on every request, self::BODY_HEADERS on
 * requests with a body. Request::create() adds browser defaults, which are removed first.
 */
trait IdpClient
{
    use ServerFixture;
    private const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    private const string LIST_RESPONSE = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    private const array BROWSER_DEFAULTS = ['User-Agent', 'Accept', 'Accept-Language', 'Accept-Charset', 'Content-Type'];

    private ScimServer $scim;

    /**
     * @param list<array<string, mixed>> $users
     * @param list<array<string, mixed>> $groups
     */
    private function seed(array $users = [], array $groups = []): void
    {
        $this->scim = $this->server($users, $groups);
    }

    /**
     * @param array<array-key, mixed>|null $body    sent as JSON
     * @param array<string, string>        $headers added to the IdP headers
     */
    private function request(string $method, string $uri, ?array $body = null, array $headers = []): Response
    {
        $request = Request::create(self::BASE_URL.$uri, $method, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        foreach (self::BROWSER_DEFAULTS as $header) {
            $request->headers->remove($header);
        }

        $request->headers->add([...self::HEADERS, ...(null === $body ? [] : self::BODY_HEADERS), ...$headers]);

        return $this->scim->handle($request, '/scim/v2');
    }

    /**
     * @param array<string, mixed> ...$operations
     *
     * @return array<string, mixed>
     */
    private static function patchOp(array ...$operations): array
    {
        return ['schemas' => [self::PATCH_OP], 'Operations' => $operations];
    }

    private static function idOf(Response $response): string
    {
        $id = self::json($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function resources(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        $resources = self::json($response)['Resources'] ?? null;
        self::assertIsArray($resources);
        self::assertContainsOnlyArray($resources);

        return array_values($resources);
    }

    private static function assertEmptyListResponse(Response $response, int $startIndex = 1): void
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/scim+json', $response->headers->get('Content-Type'));
        self::assertSame(['schemas' => [self::LIST_RESPONSE], 'totalResults' => 0, 'itemsPerPage' => 0, 'startIndex' => $startIndex, 'Resources' => []], self::json($response));
    }

    private static function assertNoContent(Response $response): void
    {
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('', $response->getContent());
    }
}
