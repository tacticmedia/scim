<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Event\ResourceCreated;
use TacticMedia\ScimServer\Event\ResourceDeleted;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServer\Tests\Server\Fixtures\BulkFixture;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class BulkTest extends KernelTestCase
{
    use BulkFixture;
    use ServerFixture;
    private const string BULK_RESPONSE = 'urn:ietf:params:scim:api:messages:2.0:BulkResponse';

    #[Test]
    #[TestDox('POST /Bulk creates a user and a group whose member references the user by bulkId (RFC 7644 3.7.2)')]
    public function createsWithReference(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'qwerty', 'data' => ['schemas' => [self::USER], 'userName' => 'Alice']],
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'ytrewq', 'data' => ['schemas' => [self::GROUP], 'displayName' => 'Tour Guides', 'members' => [['type' => 'User', 'value' => 'bulkId:qwerty']]]],
        ]);

        $alice = $this->idOf($this->users, 'userName', 'Alice');
        $tourGuides = $this->idOf($this->groups, 'displayName', 'Tour Guides');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/scim+json', $response->headers->get('Content-Type'));
        self::assertSame(['schemas' => [self::BULK_RESPONSE], 'Operations' => [
            ['location' => self::BASE_URL.'/Users/'.$alice, 'method' => 'POST', 'bulkId' => 'qwerty', 'version' => 'W/"1"', 'status' => '201'],
            ['location' => self::BASE_URL.'/Groups/'.$tourGuides, 'method' => 'POST', 'bulkId' => 'ytrewq', 'version' => 'W/"1"', 'status' => '201'],
        ]], self::json($response));
        self::assertSame([['type' => 'User', 'value' => $alice]], ($this->groups->get($tourGuides) ?? [])['members'] ?? null);
    }

    #[Test]
    #[TestDox('An operation that references a later POST runs after it; the response keeps the request order')]
    public function reordersForwardReference(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'g', 'data' => ['displayName' => 'Tour Guides', 'members' => [['value' => 'bulkId:u']]]],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'u', 'data' => ['userName' => 'Alice']],
        ]);

        $alice = $this->idOf($this->users, 'userName', 'Alice');
        self::assertSame(['g', 'u'], array_column($this->operations($response), 'bulkId'));
        self::assertSame(['201', '201'], array_column($this->operations($response), 'status'));
        self::assertSame([['value' => $alice]], ($this->groups->get($this->idOf($this->groups, 'displayName', 'Tour Guides')) ?? [])['members'] ?? null);
        self::assertSame(['User', 'Group'], array_map(static fn (object $event): string => $event instanceof ResourceCreated ? $event->type->name : '', $this->events));
    }

    #[Test]
    #[TestDox('A bulkId reference in an extension attribute resolves too (RFC 7644 3.7.2, manager.value)')]
    public function resolvesExtensionReference(): void
    {
        $server = $this->server();

        $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'qwerty', 'data' => ['userName' => 'Alice']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'ytrewq', 'data' => ['userName' => 'Bob', self::ENTERPRISE => ['employeeNumber' => '11250', 'manager' => ['value' => 'bulkId:qwerty']]]],
        ]);

        $bob = $this->users->get($this->idOf($this->users, 'userName', 'Bob')) ?? [];
        self::assertSame(['employeeNumber' => '11250', 'manager' => ['value' => $this->idOf($this->users, 'userName', 'Alice')]], $bob[self::ENTERPRISE] ?? null);
    }

    #[Test]
    #[TestDox('PUT, PATCH and DELETE with a matching version run as single requests: status, location, new version, events')]
    public function modifiesWithVersion(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen'), self::storedUser('u2', 'asmith'), self::storedUser('u3', 'cjones')]);

        $response = $this->bulk($server, [
            ['method' => 'PUT', 'path' => '/Users/u1', 'version' => 'W/"1"', 'data' => ['userName' => 'Bob']],
            ['method' => 'PATCH', 'path' => '/Users/u2', 'version' => 'W/"1"', 'data' => ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'add', 'path' => 'nickName', 'value' => 'Dave']]]],
            ['method' => 'DELETE', 'path' => '/Users/u3', 'version' => 'W/"1"'],
        ]);

        self::assertSame([
            ['location' => self::BASE_URL.'/Users/u1', 'method' => 'PUT', 'version' => 'W/"2"', 'status' => '200'],
            ['location' => self::BASE_URL.'/Users/u2', 'method' => 'PATCH', 'version' => 'W/"2"', 'status' => '204'],
            ['location' => self::BASE_URL.'/Users/u3', 'method' => 'DELETE', 'status' => '204'],
        ], $this->operations($response));
        self::assertSame('Bob', ($this->users->get('u1') ?? [])['userName'] ?? null);
        self::assertSame('Dave', ($this->users->get('u2') ?? [])['nickName'] ?? null);
        self::assertNull($this->users->get('u3'));
        self::assertSame([ResourceUpdated::class, ResourceUpdated::class, ResourceDeleted::class], array_map(get_class(...), $this->events));
    }

    #[Test]
    #[TestDox('PATCH adds a member that a POST of the same request created')]
    public function patchesWithReference(): void
    {
        $server = $this->server([], [self::storedGroup('g1', 'Admins')]);

        $this->bulk($server, [
            ['method' => 'PATCH', 'path' => '/Groups/g1', 'data' => ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => 'bulkId:alice']]]]]],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'alice', 'data' => ['userName' => 'Alice']],
        ]);

        self::assertSame([['value' => $this->idOf($this->users, 'userName', 'Alice')]], ($this->groups->get('g1') ?? [])['members'] ?? null);
    }

    #[Test]
    #[TestDox('Without failOnErrors every operation runs; each failure has its status and Error response (RFC 7644 3.7.3)')]
    public function continuesAfterErrors(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen'), self::storedUser('u2', 'asmith')]);

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'qwerty', 'data' => ['userName']],
            ['method' => 'PUT', 'path' => '/Users/u1', 'version' => 'W/"3694e05e9dff591"', 'data' => ['userName' => 'Bob']],
            ['method' => 'PATCH', 'path' => '/Users/u2', 'version' => 'W/"edac3253e2c0ef2"', 'data' => ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'remove', 'path' => 'nickName']]]],
            ['method' => 'DELETE', 'path' => '/Users/e9025315', 'version' => 'W/"0ee8add0a938e1a"'],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'ok', 'data' => ['userName' => 'carol']],
        ]);

        $preconditionFailed = ['schemas' => [self::ERROR], 'status' => '412', 'detail' => 'Failed to update. Resource has changed on the server.'];
        self::assertSame([
            ['method' => 'POST', 'bulkId' => 'qwerty', 'status' => '400', 'response' => ['schemas' => [self::ERROR], 'status' => '400', 'scimType' => 'invalidSyntax', 'detail' => 'The request body must be a JSON object.']],
            ['location' => self::BASE_URL.'/Users/u1', 'method' => 'PUT', 'status' => '412', 'response' => $preconditionFailed],
            ['location' => self::BASE_URL.'/Users/u2', 'method' => 'PATCH', 'status' => '412', 'response' => $preconditionFailed],
            ['location' => self::BASE_URL.'/Users/e9025315', 'method' => 'DELETE', 'status' => '404', 'response' => ['schemas' => [self::ERROR], 'status' => '404', 'detail' => 'Resource e9025315 not found.']],
            ['location' => self::BASE_URL.'/Users/'.$this->idOf($this->users, 'userName', 'carol'), 'method' => 'POST', 'bulkId' => 'ok', 'version' => 'W/"1"', 'status' => '201'],
        ], $this->operations($response));
    }

    #[Test]
    #[TestDox('failOnErrors stops after that many errors; the response has the operations that ran (RFC 7644 3.7.3)')]
    public function stopsAfterFailOnErrors(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'alice']],
            ['method' => 'DELETE', 'path' => '/Users/x1'],
            ['method' => 'DELETE', 'path' => '/Users/x2'],
            ['method' => 'DELETE', 'path' => '/Users/u1'],
        ], ['failOnErrors' => 2]);

        self::assertSame(['201', '404', '404'], array_column($this->operations($response), 'status'));
        self::assertNotNull($this->users->get('u1'));
    }

    #[Test]
    #[TestDox('An operation with invalid UTF-8 in its path fails alone; the response reports the operations that ran')]
    public function failsInvalidUtf8Path(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'alice']],
            ['method' => 'DELETE', 'path' => '/Users/%FF'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['201', '404'], array_column($this->operations($response), 'status'));
        self::assertSame("Resource \u{FFFD} not found.", ($this->operations($response)[1]['response'] ?? [])['detail'] ?? null);
    }

    #[Test]
    #[TestDox('An operation whose data holds a number beyond the float range fails alone with 400 invalidValue')]
    public function failsNumberBeyondFloatRange(): void
    {
        $server = $this->server();

        $response = $this->send($server, 'POST', '/Bulk', '{"schemas":["'.self::BULK_REQUEST.'"],"Operations":[{"method":"POST","path":"/Users","bulkId":"a","data":{"userName":"alice"}},{"method":"POST","path":"/Users","bulkId":"b","data":{"userName":"bob","x":1e999}}]}');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['201', '400'], array_column($this->operations($response), 'status'));
        self::assertSame(
            ['schemas' => [self::ERROR], 'status' => '400', 'scimType' => 'invalidValue', 'detail' => '"data" cannot be encoded as JSON: Inf and NaN cannot be JSON encoded.'],
            $this->operations($response)[1]['response'] ?? null,
        );
    }

    #[Test]
    #[TestDox('failOnErrors also stops inside a cycle')]
    public function stopsInsideCycle(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'bulkId:b']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'b', 'data' => ['userName' => 'bulkId:a']],
        ], ['failOnErrors' => 1]);

        self::assertSame([['method' => 'POST', 'bulkId' => 'a', 'status' => '409']], array_map(static fn (array $operation): array => array_diff_key($operation, ['response' => true]), $this->operations($response)));
    }

    #[Test]
    #[TestDox('A reference to a failed POST answers 409, to an undefined bulkId 400 invalidValue')]
    public function failsUnresolvableReferences(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'x', 'data' => ['displayName' => 'No userName']],
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'g1', 'data' => ['displayName' => 'A', 'members' => [['value' => 'bulkId:x']]]],
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'g2', 'data' => ['displayName' => 'B', 'members' => [['value' => 'bulkId:nope']]]],
        ]);

        self::assertSame([
            ['schemas' => [self::ERROR], 'status' => '400', 'scimType' => 'invalidValue', 'detail' => 'Attribute "userName" is required.'],
            ['schemas' => [self::ERROR], 'status' => '409', 'detail' => 'The POST operation with the bulkId "x" failed.'],
            ['schemas' => [self::ERROR], 'status' => '400', 'scimType' => 'invalidValue', 'detail' => 'No POST operation of the request has the bulkId "nope".'],
        ], array_column($this->operations($response), 'response'));
    }

    #[Test]
    #[TestDox('Uniqueness applies across the operations of one request, as for single requests')]
    public function checksUniqueness(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'alice']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'b', 'data' => ['userName' => 'ALICE']],
        ]);

        self::assertSame(['201', '409'], array_column($this->operations($response), 'status'));
        self::assertSame('The value of attribute "userName" is already in use.', ($this->operations($response)[1]['response'] ?? [])['detail'] ?? null);
        self::assertCount(1, $this->events);
    }
}
