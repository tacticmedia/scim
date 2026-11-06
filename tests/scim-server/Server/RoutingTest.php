<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class RoutingTest extends KernelTestCase
{
    use ServerFixture;

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentPaths(): iterable
    {
        yield 'endpoint in another case' => ['/uSERS/u1'];
        yield 'trailing slash' => ['/Users/u1/'];
        yield 'double slash (Microsoft Entra ID)' => ['//Users//u1'];
    }

    #[Test]
    #[DataProvider('equivalentPaths')]
    #[TestDox('Routes a path with $_dataName')]
    public function routesEquivalentPaths(string $path): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        self::assertSame(200, $this->send($server, 'GET', $path)->getStatusCode());
    }

    #[Test]
    #[TestDox('Decodes the id in the path')]
    public function decodesId(): void
    {
        $server = $this->server([self::storedUser('a b/c', 'bjensen')]);

        $response = $this->send($server, 'GET', '/Users/a%20b%2Fc');

        self::assertSame('a b/c', self::json($response)['id']);
        self::assertSame(self::BASE_URL.'/Users/a%20b%2Fc', (self::json($response)['meta'] ?? [])['location'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownPaths(): iterable
    {
        yield 'unknown endpoint' => ['/Devices'];
        yield 'too many segments' => ['/Users/u1/emails'];
        yield 'sub-path of a fixed endpoint' => ['/Me/u1'];
    }

    #[Test]
    #[DataProvider('unknownPaths')]
    #[TestDox('Answers 404 for $_dataName')]
    public function answersNotFoundForUnknownPath(string $path): void
    {
        $server = $this->server();

        self::assertScimError($this->send($server, 'GET', $path), 404, null, \sprintf('The endpoint /scim/v2%s does not exist.', $path));
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, int, string|null, string}>
     */
    public static function invalidUtf8(): iterable
    {
        yield 'a resource id' => ['GET', '/Users/%FF', [], 404, null, "Resource \u{FFFD} not found."];
        yield 'a truncated resource id' => ['DELETE', '/Users/%C3', [], 404, null, "Resource \u{FFFD} not found."];
        yield 'a schema URN' => ['GET', '/Schemas/%FF', [], 404, null, "Schema \u{FFFD} not found."];
        yield 'a resource type name' => ['GET', '/ResourceTypes/%FF', [], 404, null, "Resource type \u{FFFD} not found."];
        yield 'sortBy' => ['GET', '/Users?sortBy=%FF', [], 400, 'invalidValue', "\"\u{FFFD}\" is not a valid sortBy attribute path."];
        yield 'sortOrder' => ['GET', '/Users?sortOrder=%FF', [], 400, 'invalidValue', "The sortOrder \"\u{FFFD}\" is not \"ascending\" or \"descending\"."];
        yield 'Content-Type' => ['POST', '/Users', ['Content-Type' => "text/\xFF"], 415, null, "The media type \"text/\u{FFFD}\" is not supported. Send application/scim+json."];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('invalidUtf8')]
    #[TestDox('Invalid UTF-8 in $_dataName answers a SCIM error, not 500')]
    public function answersInvalidUtf8(string $method, string $uri, array $headers, int $status, ?string $scimType, string $detail): void
    {
        $server = $this->server();

        self::assertScimError($this->send($server, $method, $uri, headers: $headers), $status, $scimType, $detail);
    }

    #[Test]
    #[TestDox('Answers 404 for a path outside the base path')]
    public function answersNotFoundOutsideBasePath(): void
    {
        $server = $this->server();

        $response = $server->handle(Request::create('https://example.com/scim/v2x/Users'), '/scim/v2');

        self::assertScimError($response, 404, null, 'The endpoint /scim/v2x/Users does not exist.');
    }

    #[Test]
    #[TestDox('Serves the API at the root with an empty base path')]
    public function servesAtRoot(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        $response = $server->handle(Request::create('https://example.com/Users/u1'), '');

        self::assertSame('https://example.com/Users/u1', (self::json($response)['meta'] ?? [])['location'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function wrongMethods(): iterable
    {
        yield 'PUT on a collection' => ['PUT', '/Users', 'GET, HEAD, POST'];
        yield 'POST on a resource' => ['POST', '/Users/u1', 'GET, HEAD, PUT, PATCH, DELETE'];
        yield 'GET on .search' => ['GET', '/Users/.search', 'POST'];
        yield 'GET on the root .search' => ['GET', '/.search', 'POST'];
        yield 'DELETE on a discovery endpoint' => ['DELETE', '/Schemas', 'GET, HEAD'];
        yield 'GET on /Bulk' => ['GET', '/Bulk', 'POST'];
        yield 'HEAD on .search' => ['HEAD', '/Users/.search', 'POST'];
    }

    #[Test]
    #[DataProvider('wrongMethods')]
    #[TestDox('Answers 405 with an Allow header for $_dataName')]
    public function answersMethodNotAllowed(string $method, string $path, string $allowed): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        $response = $this->send($server, $method, $path);

        self::assertScimError($response, 405, null, \sprintf('The method %s is not allowed. Allowed: %s.', $method, $allowed));
        self::assertSame($allowed, $response->headers->get('Allow'));
    }

    #[Test]
    #[TestDox('Answers 501 for /Me')]
    public function answersNotImplemented(): void
    {
        $server = $this->server();

        self::assertScimError($this->send($server, 'PATCH', '/Me'), 501, null, 'The /Me endpoint is not supported.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function getPaths(): iterable
    {
        yield 'a resource' => ['/Users/u1'];
        yield 'an unknown resource' => ['/Users/u9'];
        yield 'a collection' => ['/Users?filter=userName%20eq%20%22bjensen%22'];
        yield 'the root' => ['/'];
        yield 'ServiceProviderConfig' => ['/ServiceProviderConfig'];
        yield 'a resource type' => ['/ResourceTypes/User'];
        yield 'a schema' => ['/Schemas/urn:ietf:params:scim:schemas:core:2.0:User'];
        yield '/Me' => ['/Me'];
    }

    #[Test]
    #[DataProvider('getPaths')]
    #[TestDox('HEAD of $_dataName answers the status and headers of GET; Response::prepare() drops the body')]
    public function answersHeadAsGet(string $path): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        $get = $this->send($server, 'GET', $path);
        $head = $this->send($server, 'HEAD', $path);

        self::assertSame($get->getStatusCode(), $head->getStatusCode());
        self::assertSame(array_diff_key($get->headers->all(), ['date' => true]), array_diff_key($head->headers->all(), ['date' => true]));
        self::assertNotSame('', $head->getContent());
        self::assertSame('', $head->prepare(Request::create(self::BASE_URL.$path, 'HEAD'))->getContent());
    }
}
