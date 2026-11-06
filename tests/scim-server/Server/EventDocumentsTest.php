<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use TacticMedia\ScimServer\Event\ResourceCreated;
use TacticMedia\ScimServer\Event\ResourceDeleted;
use TacticMedia\ScimServer\Event\ResourcePatched;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServer\Tests\Server\Fixtures\MemberDeltaRepository;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

/**
 * Events carry no value that is never returned, such as the password, in the documents and in the PATCH request.
 */
final class EventDocumentsTest extends KernelTestCase
{
    use ServerFixture;
    private const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    private const string VAULT = 'urn:example:scim:schemas:extension:2.0:Vault';

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
        yield 'PATCH' => ['PATCH', ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'replace', 'path' => 'password', 'value' => 'n3w']]]];
        yield 'PATCH without path' => ['PATCH', ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'replace', 'value' => ['password' => 'n3w']]]]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('updates')]
    #[TestDox('ResourceUpdated of $_dataName carries no password')]
    public function updatedWithoutPassword(string $method, array $body): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen', ['password' => 'old'])]);

        $this->send($server, $method, '/Users/u1', $body);

        $event = $this->events[0] ?? null;
        self::assertInstanceOf(ResourceUpdated::class, $event);
        self::assertSame([$this->withoutPassword(self::storedUser('u1', 'bjensen', ['password' => 'old'])), $this->withoutPassword((array) $this->users->get('u1'))], [$event->before, $event->after]);
        self::assertSame('n3w', $this->users->get('u1')['password'] ?? null);
        self::assertStringNotContainsString('n3w', var_export($event, true));
    }

    #[Test]
    #[TestDox('ResourcePatched carries no value of a writeOnly attribute')]
    public function patchedWithoutWriteOnlyValue(): void
    {
        $type = new ResourceType('Group', '/Groups', CoreSchemas::group(), [new SchemaExtension(new Schema(self::VAULT, 'Vault', 'Vault', [new AttributeDefinition('secret', mutability: Mutability::WriteOnly)]))]);
        $groups = new InMemoryResourceRepository($type, new MockClock(), [self::storedGroup('g1', 'Admins')]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ResourcePatched::class, function (ResourcePatched $event): void {
            $this->events[] = $event;
        });
        $server = new ScimServer([new RegisteredResourceType($type, new MemberDeltaRepository($groups, [new AttributePath(self::GROUP, 'members')]))], eventDispatcher: $dispatcher);

        $this->send($server, 'PATCH', '/Groups/g1', ['schemas' => [self::PATCH_OP], 'Operations' => [
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => 'u1']]],
            ['op' => 'add', 'path' => self::VAULT.':secret', 'value' => 'n3w'],
        ]]);

        self::assertCount(1, $this->events);
        self::assertSame('n3w', ($groups->get('g1') ?? [])[self::VAULT]['secret'] ?? null);
        self::assertStringNotContainsString('n3w', var_export($this->events, true));
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
