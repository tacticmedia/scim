<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Event\ResourceCreated;
use TacticMedia\ScimServer\Event\ResourceDeleted;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

/**
 * Events carry no value that is never returned, such as the password.
 */
final class EventDocumentsTest extends KernelTestCase
{
    use ServerFixture;

    #[Test]
    #[TestDox('ResourceCreated carries the created document without the password')]
    public function createdWithoutPassword(): void
    {
        $server = $this->server();

        $this->send($server, 'POST', '/Users', ['userName' => 'bjensen', 'password' => 't1meMa$heen']);

        $event = $this->events[0] ?? null;
        self::assertInstanceOf(ResourceCreated::class, $event);
        self::assertSame('bjensen', $event->resource['userName'] ?? null);
        self::assertArrayNotHasKey('password', $event->resource);
        self::assertSame('t1meMa$heen', $this->users->get((string) $event->resource['id'])['password'] ?? null);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function updates(): iterable
    {
        yield 'PUT' => ['PUT', ['userName' => 'babs', 'password' => 'n3w']];
        yield 'PATCH' => ['PATCH', ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'replace', 'path' => 'password', 'value' => 'n3w']]]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('updates')]
    #[TestDox('ResourceUpdated of $_dataName carries both documents without the password')]
    public function updatedWithoutPassword(string $method, array $body): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen', ['password' => 'old'])]);

        $this->send($server, $method, '/Users/u1', $body);

        $event = $this->events[0] ?? null;
        self::assertInstanceOf(ResourceUpdated::class, $event);
        self::assertSame([$this->withoutPassword(self::storedUser('u1', 'bjensen', ['password' => 'old'])), $this->withoutPassword((array) $this->users->get('u1'))], [$event->before, $event->after]);
        self::assertSame('n3w', $this->users->get('u1')['password'] ?? null);
    }

    #[Test]
    #[TestDox('ResourceDeleted carries the deleted document without the password')]
    public function deletedWithoutPassword(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen', ['password' => 'old'])]);

        $this->send($server, 'DELETE', '/Users/u1');

        self::assertEquals([new ResourceDeleted(CoreResourceTypes::user(), self::storedUser('u1', 'bjensen'))], $this->events);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function withoutPassword(array $document): array
    {
        unset($document['password']);

        return $document;
    }
}
