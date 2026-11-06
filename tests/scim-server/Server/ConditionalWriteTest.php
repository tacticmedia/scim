<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServer\Tests\Server\Fixtures\RacingRepository;
use TacticMedia\ScimServer\Tests\Server\Fixtures\RecordingRepository;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

/**
 * The version that PUT, PATCH and DELETE pass to the repository, so that it can make If-Match atomic.
 */
final class ConditionalWriteTest extends KernelTestCase
{
    use ServerFixture;
    private const array PATCH_BODY = ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'add', 'path' => 'nickName', 'value' => 'Babs']]];

    /**
     * @return iterable<string, array{string, array<string, mixed>|null, string}>
     */
    public static function writes(): iterable
    {
        yield 'PUT' => ['PUT', ['userName' => 'babs'], 'replace'];
        yield 'PATCH' => ['PATCH', self::PATCH_BODY, 'replace'];
        yield 'DELETE' => ['DELETE', null, 'delete'];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('writes')]
    #[TestDox('$_dataName with If-Match passes the stored meta.version, not the client tag')]
    public function passesStoredVersion(string $method, ?array $body, string $write): void
    {
        $users = new RecordingRepository($this->inMemory(self::storedUser('u1', 'bjensen')));

        $response = $this->send($this->serverOf($users), $method, '/Users/u1', $body, ['If-Match' => '"1"']);

        self::assertLessThan(300, $response->getStatusCode());
        self::assertSame([$write.' u1 version=W/"1"'], $users->writes);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|null, string, string|null}>
     */
    public static function unconditionalWrites(): iterable
    {
        foreach (self::writes() as $name => [$method, $body, $write]) {
            yield $name.' without If-Match' => [$method, $body, $write, null];
            yield $name.' with If-Match: *' => [$method, $body, $write, '*'];
        }
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('unconditionalWrites')]
    #[TestDox('$_dataName passes no version')]
    public function passesNoVersion(string $method, ?array $body, string $write, ?string $ifMatch): void
    {
        $users = new RecordingRepository($this->inMemory(self::storedUser('u1', 'bjensen')));

        $this->send($this->serverOf($users), $method, '/Users/u1', $body, null === $ifMatch ? [] : ['If-Match' => $ifMatch]);

        self::assertSame([$write.' u1 version=null'], $users->writes);
    }

    #[Test]
    #[TestDox('A document without meta.version gives no version, also when If-Match matches its computed ETag')]
    public function passesNoVersionWithoutStoredVersion(): void
    {
        $users = new RecordingRepository($this->inMemory(['schemas' => [self::USER], 'id' => 'u1', 'userName' => 'bjensen']));
        $server = $this->serverOf($users);
        $etag = (string) $this->send($server, 'GET', '/Users/u1')->headers->get('ETag');

        $response = $this->send($server, 'DELETE', '/Users/u1', headers: ['If-Match' => $etag]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(['delete u1 version=null'], $users->writes);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|null}>
     */
    public static function requests(): iterable
    {
        foreach (self::writes() as $name => [$method, $body]) {
            yield $name => [$method, $body];
        }
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('requests')]
    #[TestDox('$_dataName answers 412 without an event when the resource changed after the If-Match check')]
    public function rejectsConcurrentChange(string $method, ?array $body): void
    {
        $users = $this->inMemory(self::storedUser('u1', 'bjensen'));
        $server = $this->serverOf(new RacingRepository($users));

        $response = $this->send($server, $method, '/Users/u1', $body, ['If-Match' => 'W/"1"']);

        self::assertScimError($response, 412, null, 'Failed to update. Resource has changed on the server.');
        self::assertSame([], $this->events);
        self::assertSame('bjensen', $users->get('u1')['userName'] ?? null);
    }

    #[Test]
    #[TestDox('A write without If-Match succeeds after a concurrent change')]
    public function writesUnconditionallyAfterConcurrentChange(): void
    {
        $server = $this->serverOf(new RacingRepository($this->inMemory(self::storedUser('u1', 'bjensen'))));

        self::assertSame(200, $this->send($server, 'PUT', '/Users/u1', ['userName' => 'babs'])->getStatusCode());
    }

    #[Test]
    #[TestDox('A Bulk operation with a version fails with 412 when the resource changed after the check')]
    public function rejectsConcurrentChangeInBulk(): void
    {
        $server = $this->serverOf(new RacingRepository($this->inMemory(self::storedUser('u1', 'bjensen'))));

        $response = $this->send($server, 'POST', '/Bulk', [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:BulkRequest'],
            'Operations' => [['method' => 'PUT', 'path' => '/Users/u1', 'version' => 'W/"1"', 'data' => ['userName' => 'babs']]],
        ]);

        $operations = self::json($response)['Operations'] ?? null;
        self::assertIsArray($operations);
        self::assertSame('412', $operations[0]['status'] ?? null);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function inMemory(array $user): InMemoryResourceRepository
    {
        return new InMemoryResourceRepository(CoreResourceTypes::user(), new MockClock('2026-11-05T10:00:00Z'), [$user]);
    }

    private function serverOf(ResourceRepository $users): ScimServer
    {
        return new ScimServer([new RegisteredResourceType(CoreResourceTypes::user(), $users)], eventDispatcher: $this->recordingDispatcher());
    }
}
