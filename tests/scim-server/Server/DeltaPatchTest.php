<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use TacticMedia\ScimServer\Event\ResourcePatched;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Repository\ResourcePatch;
use TacticMedia\ScimServer\Repository\ValuesChange;
use TacticMedia\ScimServer\Repository\ValuesStep;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServer\Tests\Server\Fixtures\MemberDeltaRepository;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

/**
 * PATCH through a DeltaRepository that changes Group "members" by delta.
 */
final class DeltaPatchTest extends KernelTestCase
{
    use ServerFixture;
    private const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    private const string LIGHT_GET = 'g1 excludedAttributes='.self::GROUP.':members';
    private const array ENTRA_ADD = ['op' => 'Add', 'path' => 'members', 'value' => [['$ref' => null, 'value' => 'u2']]];

    private MemberDeltaRepository $deltas;

    #[Test]
    #[TestDox('An Entra member add reads the group without members, passes an Add step and answers 204 with the new ETag')]
    public function addsMember(): void
    {
        $server = $this->deltaServer();

        $response = $this->send($server, 'PATCH', '/Groups/g1', $this->patch(self::ENTRA_ADD));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame('W/"2"', $response->headers->get('ETag'));
        self::assertSame([self::LIGHT_GET], $this->deltas->gets);
        self::assertEquals([new ResourcePatch(null, [new ValuesStep(self::members(), PatchOperationType::Add, [['value' => 'u2']])], null)], $this->deltas->patches);
        self::assertSame([['value' => 'u1'], ['value' => 'u2']], ($this->groups->get('g1') ?? [])['members'] ?? null);
    }

    #[Test]
    #[TestDox('ResourcePatched carries the documents without members and the effective changes')]
    public function dispatchesResourcePatched(): void
    {
        $server = $this->deltaServer();
        $body = $this->patch(['op' => 'replace', 'path' => 'displayName', 'value' => 'Guides'], self::ENTRA_ADD, ['op' => 'remove', 'path' => 'members[value eq "u1"]']);

        $this->send($server, 'PATCH', '/Groups/g1', $body);

        $after = self::storedGroup('g1', 'Guides');
        $after['meta'] = ['created' => '2026-11-01T08:00:00Z', 'lastModified' => '2026-11-05T10:00:00Z', 'version' => 'W/"2"'];
        self::assertEquals([new ResourcePatched(
            CoreResourceTypes::group(),
            self::storedGroup('g1', 'Tour Guides'),
            $after,
            [new ValuesChange(self::members(), [['value' => 'u2']], []), new ValuesChange(self::members(), [], [['value' => 'u1']])],
            PatchRequest::fromArray($body),
        )], $this->events);
    }

    #[Test]
    #[TestDox('The document of a rename is normalized and complete without members')]
    public function passesRenamedDocument(): void
    {
        $server = $this->deltaServer();

        $this->send($server, 'PATCH', '/Groups/g1', $this->patch(['op' => 'replace', 'value' => ['displayName' => 'Guides', 'externalId' => 'e1']]));

        self::assertSame([[
            'schemas' => [self::GROUP],
            'id' => 'g1',
            'externalId' => 'e1',
            'meta' => ['created' => '2026-11-01T08:00:00Z', 'lastModified' => '2026-11-01T08:00:00Z', 'version' => 'W/"1"'],
            'displayName' => 'Guides',
        ]], array_map(static fn (ResourcePatch $patch): ?array => $patch->document, $this->deltas->patches));
        self::assertSame([['value' => 'u1']], ($this->groups->get('g1') ?? [])['members'] ?? null);
    }

    #[Test]
    #[TestDox('A PATCH that changes nothing calls no write and dispatches nothing (Okta rename)')]
    public function skipsNoOperation(): void
    {
        $server = $this->deltaServer();

        $response = $this->send($server, 'PATCH', '/Groups/g1', $this->patch(['op' => 'replace', 'value' => ['id' => 'g1', 'displayName' => 'Tour Guides']]));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('W/"1"', $response->headers->get('ETag'));
        self::assertSame([self::LIGHT_GET], $this->deltas->gets);
        self::assertSame([], $this->deltas->patches);
        self::assertSame([], $this->events);
    }

    #[Test]
    #[TestDox('A step without effective change keeps the ETag and dispatches nothing')]
    public function dispatchesNothingWithoutChange(): void
    {
        $server = $this->deltaServer();

        $response = $this->send($server, 'PATCH', '/Groups/g1', $this->patch(['op' => 'add', 'path' => 'members', 'value' => [['value' => 'u1']]]));

        self::assertSame('W/"1"', $response->headers->get('ETag'));
        self::assertCount(1, $this->deltas->patches);
        self::assertSame([], $this->events);
    }

    #[Test]
    #[TestDox('A ValuesChange without values dispatches nothing')]
    public function ignoresEmptyChange(): void
    {
        $server = $this->deltaServer();
        $this->deltas->reportsEveryStep = true;

        $this->send($server, 'PATCH', '/Groups/g1', $this->patch(['op' => 'add', 'path' => 'members', 'value' => [['value' => 'u1']]]));

        self::assertSame([], $this->events);
    }

    #[Test]
    #[TestDox('ResourcePatched leaves out a ValuesChange without values')]
    public function dispatchesOnlyEffectiveChanges(): void
    {
        $server = $this->deltaServer();
        $this->deltas->reportsEveryStep = true;

        $this->send($server, 'PATCH', '/Groups/g1', $this->patch(['op' => 'add', 'path' => 'members', 'value' => [['value' => 'u1']]], self::ENTRA_ADD));

        $event = $this->events[0] ?? null;
        self::assertInstanceOf(ResourcePatched::class, $event);
        self::assertEquals([new ValuesChange(self::members(), [['value' => 'u2']], [])], $event->changes);
    }

    /**
     * @return iterable<string, array{array<string, string>, string|null}>
     */
    public static function versions(): iterable
    {
        yield 'If-Match with the ETag' => [['If-Match' => 'W/"1"'], 'W/"1"'];
        yield 'If-Match: *' => [['If-Match' => '*'], null];
        yield 'no If-Match' => [[], null];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('versions')]
    #[TestDox('$_dataName passes the version $version')]
    public function passesVersion(array $headers, ?string $version): void
    {
        $server = $this->deltaServer();

        $this->send($server, 'PATCH', '/Groups/g1', $this->patch(self::ENTRA_ADD), $headers);

        self::assertSame([$version], array_map(static fn (ResourcePatch $patch): ?string => $patch->version, $this->deltas->patches));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function preconditions(): iterable
    {
        yield 'an If-Match that does not match' => [['If-Match' => 'W/"0"'], 'Failed to update. Resource has changed on the server.'];
        yield 'an If-None-Match that matches' => [['If-None-Match' => 'W/"1"'], 'The resource matches If-None-Match.'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('preconditions')]
    #[TestDox('$_dataName answers 412 before the repository call')]
    public function checksPreconditions(array $headers, string $detail): void
    {
        $server = $this->deltaServer();

        self::assertScimError($this->send($server, 'PATCH', '/Groups/g1', $this->patch(self::ENTRA_ADD), $headers), 412, null, $detail);
        self::assertSame([], $this->deltas->patches);
    }

    #[Test]
    #[TestDox('An unknown id answers 404')]
    public function answersNotFound(): void
    {
        $server = $this->deltaServer();

        self::assertScimError($this->send($server, 'PATCH', '/Groups/g9', $this->patch(self::ENTRA_ADD)), 404, null, 'Resource g9 not found.');
    }

    #[Test]
    #[TestDox('A repository error is the response, and no event is dispatched')]
    public function passesRepositoryError(): void
    {
        $server = $this->deltaServer();

        $response = $this->send($server, 'PATCH', '/Groups/g1', $this->patch(['op' => 'add', 'path' => 'members', 'value' => [['value' => MemberDeltaRepository::UNKNOWN]]]));

        self::assertScimError($response, 400, 'invalidValue', 'No resource has the id "unknown-member".');
        self::assertSame([], $this->events);
    }

    #[Test]
    #[TestDox('An error of an operation on another attribute names its index in the request')]
    public function keepsOperationIndex(): void
    {
        $server = $this->deltaServer();

        $response = $this->send($server, 'PATCH', '/Groups/g1', $this->patch(self::ENTRA_ADD, ['op' => 'remove', 'path' => 'displayName']));

        self::assertScimError($response, 400, 'mutability', 'Operations[1]: Attribute "'.self::GROUP.':displayName" is required.');
        self::assertSame([], $this->deltas->patches);
    }

    #[Test]
    #[TestDox('A rename to the name of another group answers 409 uniqueness')]
    public function checksUniqueness(): void
    {
        $server = $this->deltaServer([self::storedGroup('g2', 'Guides')]);

        $response = $this->send($server, 'PATCH', '/Groups/g1', $this->patch(['op' => 'replace', 'path' => 'displayName', 'value' => 'Guides']));

        self::assertScimError($response, 409, 'uniqueness', 'The value of attribute "displayName" is already in use.');
        self::assertSame([], $this->deltas->patches);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, list<string>}>
     */
    public static function projections(): iterable
    {
        yield 'attributes=members' => ['?attributes=members', ['schemas' => [self::GROUP], 'id' => 'g1', 'members' => [
            ['value' => 'u1', 'type' => 'User', '$ref' => self::BASE_URL.'/Users/u1'],
            ['value' => 'u2'],
        ]], [self::LIGHT_GET, 'g1 excludedAttributes=']];
    }

    /**
     * @param array<string, mixed> $resource
     * @param list<string>         $gets
     */
    #[Test]
    #[DataProvider('projections')]
    #[TestDox('PATCH with $_dataName answers 200 with the group read again with the projection hints')]
    public function answersProjectedResource(string $query, array $resource, array $gets): void
    {
        $server = $this->deltaServer(members: [['value' => 'u1', 'type' => 'User']]);

        $response = $this->send($server, 'PATCH', '/Groups/g1'.$query, $this->patch(self::ENTRA_ADD));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('W/"2"', $response->headers->get('ETag'));
        self::assertSame($resource, array_diff_key(self::json($response), ['meta' => true]));
        self::assertSame($gets, $this->deltas->gets);
    }

    /**
     * @return iterable<string, array{list<AttributePath>, array<string, mixed>}>
     */
    public static function fullPathRequests(): iterable
    {
        yield 'an operation that does not qualify' => [[self::members()], ['op' => 'remove', 'path' => 'members[value eq "u1" or value eq "u9"]']];
    }

    /**
     * @param list<AttributePath>  $deltaAttributes
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('fullPathRequests')]
    #[TestDox('$_dataName takes the full path with ResourceUpdated')]
    public function takesFullPath(array $deltaAttributes, array $operation): void
    {
        $server = $this->deltaServer(deltaAttributes: $deltaAttributes);

        $response = $this->send($server, 'PATCH', '/Groups/g1', $this->patch($operation));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(['g1 excludedAttributes='], $this->deltas->gets);
        self::assertSame([], $this->deltas->patches);
        self::assertContainsOnlyInstancesOf(ResourceUpdated::class, $this->events);
        self::assertCount(1, $this->events);
    }

    #[Test]
    #[TestDox('A group read without members and without meta.version is a LogicException')]
    public function needsVersion(): void
    {
        $group = self::storedGroup('g1', 'Tour Guides');
        unset($group['meta']);
        $server = $this->deltaServer(group: $group);

        $this->expectExceptionObject(new \LogicException('The DeltaRepository of the resource type Group returned a document without "meta.version".'));

        $this->send($server, 'PATCH', '/Groups/g1', $this->patch(self::ENTRA_ADD));
    }

    /**
     * @param list<array<string, mixed>> $others
     * @param list<array<string, mixed>> $members
     * @param list<AttributePath>|null   $deltaAttributes null: Group members
     * @param array<string, mixed>|null  $group           null: g1 "Tour Guides" with $members
     */
    private function deltaServer(array $others = [], array $members = [['value' => 'u1']], ?array $deltaAttributes = null, ?array $group = null): ScimServer
    {
        $this->clock = new MockClock('2026-11-05T10:00:00Z');
        $this->users = new InMemoryResourceRepository(CoreResourceTypes::user(), $this->clock);
        $this->groups = new InMemoryResourceRepository(CoreResourceTypes::group(), $this->clock, [$group ?? self::storedGroup('g1', 'Tour Guides', ['members' => $members]), ...$others]);
        $this->deltas = new MemberDeltaRepository($this->groups, $deltaAttributes ?? [self::members()]);
        $dispatcher = new EventDispatcher();

        foreach ([ResourceUpdated::class, ResourcePatched::class] as $event) {
            $dispatcher->addListener($event, function (object $event): void {
                $this->events[] = $event;
            });
        }

        return new ScimServer([
            new RegisteredResourceType(CoreResourceTypes::user(), $this->users),
            new RegisteredResourceType(CoreResourceTypes::group(), $this->deltas),
        ], eventDispatcher: $dispatcher);
    }

    private static function members(): AttributePath
    {
        return new AttributePath(self::GROUP, 'members');
    }

    /**
     * @param array<string, mixed> ...$operations
     *
     * @return array<string, mixed>
     */
    private function patch(array ...$operations): array
    {
        return ['schemas' => [self::PATCH_OP], 'Operations' => $operations];
    }
}
