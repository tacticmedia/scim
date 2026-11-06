<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Server\ServiceProviderConfig;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class BulkRequestTest extends KernelTestCase
{
    use ServerFixture;
    private const string BULK_REQUEST = 'urn:ietf:params:scim:api:messages:2.0:BulkRequest';
    private const array CREATE = ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'alice']];

    #[Test]
    #[TestDox('Member names and the method match in any case; a path without a leading slash works')]
    public function acceptsLenientMessage(): void
    {
        $server = $this->server();

        $response = $this->send($server, 'POST', '/Bulk', [
            'SCHEMAS' => [strtoupper(self::BULK_REQUEST)],
            'operations' => [['Method' => 'post', 'PATH' => 'Users', 'BULKID' => 'a', 'Data' => ['userName' => 'alice'], 'version' => 'ignored for POST']],
            'FailOnErrors' => 1,
        ]);

        self::assertSame(['method' => 'POST', 'bulkId' => 'a', 'version' => 'W/"1"', 'status' => '201'], array_diff_key(self::json($response)['Operations'][0] ?? [], ['location' => true]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidMessages(): iterable
    {
        $bulk = ['schemas' => [self::BULK_REQUEST]];

        yield 'no BulkRequest schema' => [['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [self::CREATE]], '"schemas" must contain "urn:ietf:params:scim:api:messages:2.0:BulkRequest".'];
        yield 'no Operations' => [$bulk, '"Operations" must be a non-empty list of operations.'];
        yield 'empty Operations' => [[...$bulk, 'Operations' => []], '"Operations" must be a non-empty list of operations.'];
        yield 'an operation that is not an object' => [[...$bulk, 'Operations' => ['POST']], 'Operations[0] must be an object.'];
        yield 'method GET' => [[...$bulk, 'Operations' => [['method' => 'GET', 'path' => '/Users/u1']]], 'Operations[0]: "method" must be "POST", "PUT", "PATCH" or "DELETE".'];
        yield 'no method' => [[...$bulk, 'Operations' => [['path' => '/Users/u1']]], 'Operations[0]: "method" must be "POST", "PUT", "PATCH" or "DELETE".'];
        yield 'no path' => [[...$bulk, 'Operations' => [['method' => 'DELETE']]], 'Operations[0]: "path" must be a non-empty string.'];
        yield 'POST without bulkId' => [[...$bulk, 'Operations' => [['method' => 'POST', 'path' => '/Users', 'data' => []]]], 'Operations[0]: a POST operation needs a "bulkId" that is a non-empty string.'];
        yield 'a version that is not a string' => [[...$bulk, 'Operations' => [['method' => 'DELETE', 'path' => '/Users/u1', 'version' => 1]]], 'Operations[0]: "version" must be a string.'];
        yield 'a bulkId used twice' => [[...$bulk, 'Operations' => [self::CREATE, ['method' => 'DELETE', 'path' => '/Users/u1', 'bulkId' => 'a'], self::CREATE]], 'The bulkId "a" is not unique.'];
        yield 'failOnErrors 0' => [[...$bulk, 'failOnErrors' => 0, 'Operations' => [self::CREATE]], '"failOnErrors" must be a positive integer.'];
        yield 'failOnErrors as a string' => [[...$bulk, 'failOnErrors' => '1', 'Operations' => [self::CREATE]], '"failOnErrors" must be a positive integer.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidMessages')]
    #[TestDox('A malformed BulkRequest answers 400 invalidSyntax and runs nothing: $_dataName')]
    public function rejectsInvalidMessage(array $body, string $detail): void
    {
        $server = $this->server();

        self::assertScimError($this->send($server, 'POST', '/Bulk', $body), 400, 'invalidSyntax', $detail);
        self::assertSame([], $this->events);
    }

    #[Test]
    #[TestDox('A body that is not a JSON object answers 400 invalidSyntax')]
    public function rejectsNonObjectBody(): void
    {
        $server = $this->server();

        self::assertScimError($this->send($server, 'POST', '/Bulk', '[]'), 400, 'invalidSyntax', 'The request body must be a JSON object.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string|null, string, string|null}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'an unknown endpoint' => [['method' => 'DELETE', 'path' => '/Devices/1'], 404, null, 'The endpoint /Devices/1 does not exist.', self::BASE_URL.'/Devices/1'];
        yield 'POST to a resource' => [['method' => 'POST', 'path' => '/Users/u1', 'bulkId' => 'a', 'data' => []], 400, 'invalidSyntax', 'POST needs the path of a resource type endpoint, for example "/Users".', null];
        yield 'POST to .search' => [['method' => 'POST', 'path' => '/Users/.search', 'bulkId' => 'a', 'data' => []], 400, 'invalidSyntax', 'POST needs the path of a resource type endpoint, for example "/Users".', null];
        yield 'POST to /Bulk' => [['method' => 'POST', 'path' => '/Bulk', 'bulkId' => 'a', 'data' => []], 400, 'invalidSyntax', 'POST needs the path of a resource type endpoint, for example "/Users".', null];
        yield 'DELETE of a collection' => [['method' => 'DELETE', 'path' => 'Users'], 400, 'invalidSyntax', 'DELETE needs the path of a resource, for example "/Users/<id>".', self::BASE_URL.'/Users'];
        yield 'POST with data {}' => [['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => new \stdClass()], 400, 'invalidValue', 'Attribute "userName" is required.', null];
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('invalidPaths')]
    #[TestDox('An operation fails alone for $_dataName; only a POST has no location (RFC 7644 3.7.3)')]
    public function failsOperation(array $operation, int $status, ?string $scimType, string $detail, ?string $location): void
    {
        $server = $this->server();

        $response = $this->send($server, 'POST', '/Bulk', ['schemas' => [self::BULK_REQUEST], 'Operations' => [$operation]]);

        $error = ['schemas' => [self::ERROR], 'status' => (string) $status, ...(null === $scimType ? [] : ['scimType' => $scimType]), 'detail' => $detail];
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($error, self::json($response)['Operations'][0]['response'] ?? null);
        self::assertSame($location, self::json($response)['Operations'][0]['location'] ?? null);
    }

    #[Test]
    #[TestDox('More operations than maxOperations answer 413 (RFC 7644 3.7.4)')]
    public function rejectsTooManyOperations(): void
    {
        $server = $this->server(config: new ServiceProviderConfig(bulkMaxOperations: 2));

        $response = $this->send($server, 'POST', '/Bulk', ['schemas' => [self::BULK_REQUEST], 'Operations' => array_fill(0, 3, self::CREATE)]);

        self::assertScimError($response, 413, null, 'The number of operations exceeds the maxOperations (2).');
    }

    /**
     * @return iterable<string, array{int<1, max>, array<string, string>}>
     */
    public static function oversizedRequests(): iterable
    {
        yield 'a body above maxPayloadSize' => [50, []];
        yield 'a Content-Length above maxPayloadSize, with a smaller body' => [1000, ['Content-Length' => '1001']];
    }

    /**
     * @param int<1, max>           $maxBytes
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('oversizedRequests')]
    #[TestDox('$_dataName answers 413 with the RFC 7644 3.7.4 detail')]
    public function rejectsOversizedPayload(int $maxBytes, array $headers): void
    {
        $server = $this->server(config: new ServiceProviderConfig(bulkMaxPayloadSizeBytes: $maxBytes));

        $response = $this->send($server, 'POST', '/Bulk', ['schemas' => [self::BULK_REQUEST], 'Operations' => [self::CREATE]], $headers);

        self::assertScimError($response, 413, null, \sprintf('The size of the bulk operation exceeds the maxPayloadSize (%d).', $maxBytes));
    }

    #[Test]
    #[TestDox('With bulk turned off, POST /Bulk answers 501')]
    public function answersNotImplementedWhenTurnedOff(): void
    {
        $server = $this->server(config: new ServiceProviderConfig(bulkSupported: false));

        self::assertScimError($this->send($server, 'POST', '/Bulk', ['schemas' => [self::BULK_REQUEST], 'Operations' => [self::CREATE]]), 501, null, 'Bulk operations are not supported.');
    }
}
