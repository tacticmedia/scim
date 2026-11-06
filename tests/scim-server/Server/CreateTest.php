<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServer\Event\ResourceCreated;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class CreateTest extends KernelTestCase
{
    use ServerFixture;

    #[Test]
    #[TestDox('POST answers 201 with Location, ETag and the stored resource with meta.resourceType, meta.location and meta.version')]
    public function createsResource(): void
    {
        $server = $this->server();

        $response = $this->send($server, 'POST', '/Users', [
            'schemas' => [self::USER],
            'userName' => 'bjensen',
            'externalId' => 'bjensen',
            'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen'],
            'password' => 't1meMa$heen',
            'groups' => [['value' => 'g1']],
            'meta' => ['resourceType' => 'Group'],
        ]);

        $id = self::json($response)['id'] ?? null;
        self::assertIsString($id);
        $location = self::BASE_URL.'/Users/'.$id;
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/scim+json', $response->headers->get('Content-Type'));
        self::assertSame($location, $response->headers->get('Location'));
        self::assertSame('W/"1"', $response->headers->get('ETag'));
        self::assertSame([
            'schemas' => [self::USER],
            'id' => $id,
            'meta' => [
                'resourceType' => 'User',
                'created' => '2026-11-05T10:00:00Z',
                'lastModified' => '2026-11-05T10:00:00Z',
                'version' => 'W/"1"',
                'location' => $location,
            ],
            'externalId' => 'bjensen',
            'userName' => 'bjensen',
            'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen'],
        ], self::json($response));
    }

    #[Test]
    #[TestDox('POST dispatches ResourceCreated with the document that the repository returned')]
    public function dispatchesResourceCreated(): void
    {
        $server = $this->server();

        $this->send($server, 'POST', '/Groups', ['schemas' => [self::GROUP], 'displayName' => 'Tour Guides', 'members' => []]);

        $stored = $this->groups->list(new ListQuery())->resources;
        self::assertCount(1, $stored);
        self::assertEquals([new ResourceCreated(CoreResourceTypes::group(), $stored[0])], $this->events);
    }

    #[Test]
    #[TestDox('POST applies the attributes parameter to the response')]
    public function projectsResponse(): void
    {
        $server = $this->server();

        $response = $this->send($server, 'POST', '/Users'.self::query(['attributes' => 'userName']), ['userName' => 'bjensen', 'title' => 'Tour Guide']);

        self::assertSame(['schemas', 'id', 'userName'], array_keys(self::json($response)));
    }

    #[Test]
    #[TestDox('POST with a userName in use, in another case, answers 409 uniqueness and stores nothing')]
    public function rejectsDuplicateUserName(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        $response = $this->send($server, 'POST', '/Users', ['userName' => 'BJensen']);

        self::assertScimError($response, 409, 'uniqueness', 'The value of attribute "userName" is already in use.');
        self::assertSame(1, $this->users->list(new ListQuery())->totalResults);
        self::assertSame([], $this->events);
    }

    #[Test]
    #[TestDox('POST of a group with a displayName in use, in another case, answers 409 uniqueness (Entra)')]
    public function rejectsDuplicateGroupDisplayName(): void
    {
        $server = $this->server(groups: [self::storedGroup('g1', 'Tour Guides')]);

        $response = $this->send($server, 'POST', '/Groups', ['displayName' => 'tour guides']);

        self::assertScimError($response, 409, 'uniqueness', 'The value of attribute "displayName" is already in use.');
        self::assertSame(1, $this->groups->list(new ListQuery())->totalResults);
    }

    #[Test]
    #[TestDox('POST with both attributes and excludedAttributes answers 400 invalidValue and stores nothing')]
    public function rejectsBothProjectionParameters(): void
    {
        $server = $this->server();

        $response = $this->send($server, 'POST', '/Users'.self::query(['attributes' => 'userName', 'excludedAttributes' => 'name']), ['userName' => 'bjensen']);

        self::assertScimError($response, 400, 'invalidValue', 'The parameters "attributes" and "excludedAttributes" are mutually exclusive.');
        self::assertSame(0, $this->users->list(new ListQuery())->totalResults);
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function acceptedBodies(): iterable
    {
        yield 'application/json with charset' => ['{"userName":"bjensen"}', ['Content-Type' => 'Application/JSON; charset=utf-8']];
        yield 'no Content-Type' => [' {"userName":"bjensen"}', ['Content-Type' => '']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('acceptedBodies')]
    #[TestDox('POST accepts a JSON object body: $_dataName')]
    public function acceptsBody(string $body, array $headers): void
    {
        $server = $this->server();

        $response = $this->send($server, 'POST', '/Users', $body, $headers);

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, int, string|null, string}>
     */
    public static function rejectedBodies(): iterable
    {
        yield 'empty body' => ['', [], 400, 'invalidSyntax', 'The request body is not valid JSON: Syntax error.'];
        yield 'JSON array' => ['[]', [], 400, 'invalidSyntax', 'The request body must be a JSON object.'];
        yield 'JSON string' => ['"bjensen"', [], 400, 'invalidSyntax', 'The request body must be a JSON object.'];
        yield 'another media type' => ['{"userName":"bjensen"}', ['Content-Type' => 'text/plain; charset=utf-8'], 415, null, 'The media type "text/plain" is not supported. Send application/scim+json.'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('rejectedBodies')]
    #[TestDox('POST rejects $_dataName')]
    public function rejectsBody(string $body, array $headers, int $status, ?string $scimType, string $detail): void
    {
        $server = $this->server();

        $response = $this->send($server, 'POST', '/Users', $body, $headers);

        self::assertScimError($response, $status, $scimType, $detail);
    }

    #[Test]
    #[TestDox('The base URL of meta.location includes the front controller path')]
    public function locationIncludesBaseUrl(): void
    {
        $server = $this->server();
        $request = Request::create('http://example.com/index.php/scim/v2/Users', 'POST', server: ['SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => '/srv/public/index.php'], content: '{"userName":"bjensen"}');
        $request->headers->set('Content-Type', 'application/scim+json');

        $response = $server->handle($request, '/scim/v2/');

        self::assertStringStartsWith('http://example.com/index.php/scim/v2/Users/', (string) $response->headers->get('Location'));
    }
}
