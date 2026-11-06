<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class ReplaceTest extends KernelTestCase
{
    use ServerFixture;
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    #[Test]
    #[TestDox('PUT replaces the resource, ignores readOnly input and answers 200 with the new ETag and resource')]
    public function replacesResource(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen', ['title' => 'Tour Guide'])]);
        $this->clock->sleep(60);

        $response = $this->send($server, 'PUT', '/Users/u1', [
            'schemas' => [self::USER],
            'id' => 'other',
            'userName' => 'babs',
            'meta' => ['resourceType' => 'User'],
            'groups' => [],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('W/"2"', $response->headers->get('ETag'));
        self::assertSame([
            'schemas' => [self::USER],
            'id' => 'u1',
            'meta' => [
                'resourceType' => 'User',
                'created' => '2026-11-01T08:00:00Z',
                'lastModified' => '2026-11-05T10:01:00Z',
                'version' => 'W/"2"',
                'location' => self::BASE_URL.'/Users/u1',
            ],
            'userName' => 'babs',
        ], self::json($response));
    }

    #[Test]
    #[TestDox('PUT dispatches ResourceUpdated with the stored and the new document and no PATCH request')]
    public function dispatchesResourceUpdated(): void
    {
        $stored = self::storedUser('u1', 'bjensen');
        $server = $this->server([$stored]);

        $this->send($server, 'PUT', '/Users/u1', ['userName' => 'babs']);

        self::assertEquals([new ResourceUpdated(CoreResourceTypes::user(), $stored, (array) $this->users->get('u1'), null)], $this->events);
    }

    #[Test]
    #[TestDox('PUT keeps the userName of the resource itself, and answers 409 for the userName of another resource')]
    public function checksUniquenessAgainstOtherResources(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen'), self::storedUser('u2', 'jsmith')]);

        self::assertSame(200, $this->send($server, 'PUT', '/Users/u1', ['userName' => 'BJENSEN'])->getStatusCode());
        self::assertScimError($this->send($server, 'PUT', '/Users/u1', ['userName' => 'JSmith']), 409, 'uniqueness', 'The value of attribute "userName" is already in use.');
        self::assertSame('BJENSEN', ($this->users->get('u1') ?? [])['userName'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function unreturnedValues(): iterable
    {
        yield 'a body without it keeps it' => [['userName' => 'babs'], 'secret'];
        yield 'a URN-qualified key replaces it' => [['userName' => 'babs', self::USER.':PASSWORD' => 'n3w'], 'n3w'];
        yield 'an explicit null clears it' => [['userName' => 'babs', 'password' => null], null];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('unreturnedValues')]
    #[TestDox('PUT and a password that GET never returns: $_dataName')]
    public function keepsUnreturnedValues(array $body, ?string $password): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen', ['password' => 'secret', self::ENTERPRISE => ['employeeNumber' => '701984']])]);

        self::assertSame(200, $this->send($server, 'PUT', '/Users/u1', $body)->getStatusCode());

        self::assertSame($password, ($this->users->get('u1') ?? [])['password'] ?? null);
    }

    #[Test]
    #[TestDox('PUT of an unknown id answers 404')]
    public function answersNotFound(): void
    {
        $server = $this->server();

        self::assertScimError($this->send($server, 'PUT', '/Users/u9', ['userName' => 'babs']), 404, null, 'Resource u9 not found.');
    }

    #[Test]
    #[TestDox('PUT with an If-Match of an older ETag answers 412 and keeps the resource')]
    public function answersIfMatch(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen', version: 'W/"3"')]);

        $response = $this->send($server, 'PUT', '/Users/u1', ['userName' => 'babs'], ['If-Match' => 'W/"2"']);

        self::assertSame(412, $response->getStatusCode());
        self::assertSame('bjensen', ($this->users->get('u1') ?? [])['userName'] ?? null);
    }

    #[Test]
    #[TestDox('PUT with If-None-Match: * answers 412 and keeps the resource (RFC 7232 section 3.2)')]
    public function answersIfNoneMatch(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen', version: 'W/"3"')]);

        $response = $this->send($server, 'PUT', '/Users/u1', ['userName' => 'babs'], ['If-None-Match' => '*']);

        self::assertSame(412, $response->getStatusCode());
        self::assertSame('bjensen', ($this->users->get('u1') ?? [])['userName'] ?? null);
    }
}
