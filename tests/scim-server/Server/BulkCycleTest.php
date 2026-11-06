<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Event\ResourceCreated;
use TacticMedia\ScimServer\Tests\Server\Fixtures\BulkFixture;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

/**
 * Circular bulkId references (RFC 7644 section 3.7.1).
 */
final class BulkCycleTest extends KernelTestCase
{
    use BulkFixture;
    use ServerFixture;

    #[Test]
    #[TestDox('RFC 7644 3.7.1 example: A is created without members, then B, then A is patched')]
    public function resolvesRfcExample(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'qwerty', 'data' => ['schemas' => [self::GROUP], 'displayName' => 'Group A', 'members' => [['type' => 'Group', 'value' => 'bulkId:ytrewq']]]],
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'ytrewq', 'data' => ['schemas' => [self::GROUP], 'displayName' => 'Group B', 'members' => [['type' => 'Group', 'value' => 'bulkId:qwerty']]]],
        ]);

        $a = $this->idOf($this->groups, 'displayName', 'Group A');
        $b = $this->idOf($this->groups, 'displayName', 'Group B');
        self::assertSame([
            ['location' => self::BASE_URL.'/Groups/'.$a, 'method' => 'POST', 'bulkId' => 'qwerty', 'version' => 'W/"2"', 'status' => '201'],
            ['location' => self::BASE_URL.'/Groups/'.$b, 'method' => 'POST', 'bulkId' => 'ytrewq', 'version' => 'W/"1"', 'status' => '201'],
        ], $this->operations($response));
        self::assertSame([['type' => 'Group', 'value' => $b]], $this->members($a));
        self::assertSame([['type' => 'Group', 'value' => $a]], $this->members($b));
        self::assertSame(['Created Group A', 'Created Group B', 'Updated Group A'], $this->eventLog());
        $created = $this->events[0] ?? null;
        self::assertInstanceOf(ResourceCreated::class, $created);
        self::assertArrayNotHasKey('members', $created->resource);
    }

    #[Test]
    #[TestDox('A cycle of three cuts the first POST; the others run as their references allow')]
    public function resolvesCycleOfThree(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            $this->group('a', 'A', ['bulkId:b']),
            $this->group('b', 'B', ['bulkId:c']),
            $this->group('c', 'C', ['bulkId:a']),
        ]);

        self::assertSame(['201', '201', '201'], array_column($this->operations($response), 'status'));
        self::assertSame(['Created A', 'Created C', 'Created B', 'Updated A'], $this->eventLog());
        self::assertSame([['value' => $this->idOf($this->groups, 'displayName', 'B')]], $this->members($this->idOf($this->groups, 'displayName', 'A')));
    }

    #[Test]
    #[TestDox('A self-reference in a group member and in the enterprise manager resolves to the own id')]
    public function resolvesSelfReferences(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            $this->group('g', 'Self', ['bulkId:g']),
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'u', 'data' => ['userName' => 'boss', self::ENTERPRISE => ['manager' => ['value' => 'bulkId:u']]]],
        ]);

        $group = $this->idOf($this->groups, 'displayName', 'Self');
        $boss = $this->idOf($this->users, 'userName', 'boss');
        self::assertSame(['201', '201'], array_column($this->operations($response), 'status'));
        self::assertSame([['value' => $group]], $this->members($group));
        self::assertSame(['manager' => ['value' => $boss]], ($this->users->get($boss) ?? [])[self::ENTERPRISE] ?? null);
    }

    #[Test]
    #[TestDox('An enterprise manager cycle keeps the other extension attributes of the cut POST')]
    public function resolvesManagerCycle(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'alice', self::ENTERPRISE => ['employeeNumber' => '1', 'manager' => ['value' => 'bulkId:b']]]],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'b', 'data' => ['userName' => 'bob', self::ENTERPRISE => ['manager' => ['value' => 'bulkId:a']]]],
        ]);

        $alice = $this->idOf($this->users, 'userName', 'alice');
        $bob = $this->idOf($this->users, 'userName', 'bob');
        self::assertSame(['201', '201'], array_column($this->operations($response), 'status'));
        self::assertSame(['employeeNumber' => '1', 'manager' => ['value' => $bob]], ($this->users->get($alice) ?? [])[self::ENTERPRISE] ?? null);
        self::assertSame(['manager' => ['value' => $alice]], ($this->users->get($bob) ?? [])[self::ENTERPRISE] ?? null);
    }

    #[Test]
    #[TestDox('The cut POST is created with its other attributes; the PATCH adds the whole multi-valued attribute')]
    public function cutsWholeAttribute(): void
    {
        $server = $this->server();

        $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'u', 'data' => ['userName' => 'alice']],
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'a', 'data' => ['displayName' => 'A', 'externalId' => 'ext-a', 'members' => [['value' => 'bulkId:u', 'display' => 'Alice'], ['value' => 'bulkId:b']]]],
            $this->group('b', 'B', ['bulkId:a']),
        ]);

        $created = $this->events[1] ?? null;
        self::assertInstanceOf(ResourceCreated::class, $created);
        $createdValues = array_intersect_key($created->resource, ['displayName' => true, 'externalId' => true, 'members' => true]);
        ksort($createdValues);
        self::assertSame(['displayName' => 'A', 'externalId' => 'ext-a'], $createdValues);
        self::assertSame(
            [['value' => $this->idOf($this->users, 'userName', 'alice')], ['value' => $this->idOf($this->groups, 'displayName', 'B')]],
            $this->members($this->idOf($this->groups, 'displayName', 'A')),
        );
    }

    #[Test]
    #[TestDox('An operation that references a POST of a cycle runs once the cut POST exists')]
    public function patchesWithCycleReference(): void
    {
        $server = $this->server([], [self::storedGroup('g1', 'Admins')]);

        $response = $this->bulk($server, [
            ['method' => 'PATCH', 'path' => '/Groups/g1', 'data' => ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => 'bulkId:qwerty']]]]]],
            $this->group('qwerty', 'Group A', ['bulkId:ytrewq']),
            $this->group('ytrewq', 'Group B', ['bulkId:qwerty']),
        ]);

        self::assertSame(['204', '201', '201'], array_column($this->operations($response), 'status'));
        self::assertSame([['value' => $this->idOf($this->groups, 'displayName', 'Group A')]], $this->members('g1'));
        self::assertSame(['Created Group A', 'Updated Admins', 'Created Group B', 'Updated Group A'], $this->eventLog());
    }

    #[Test]
    #[TestDox('Two independent cycles are cut one after the other; results keep the request order')]
    public function resolvesTwoCycles(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            $this->group('a', 'A', ['bulkId:b']),
            $this->group('b', 'B', ['bulkId:a']),
            $this->group('c', 'C', ['bulkId:d']),
            $this->group('d', 'D', ['bulkId:c']),
        ]);

        self::assertSame(['a', 'b', 'c', 'd'], array_column($this->operations($response), 'bulkId'));
        self::assertSame(['201', '201', '201', '201'], array_column($this->operations($response), 'status'));
        self::assertSame(['Created A', 'Created B', 'Updated A', 'Created C', 'Created D', 'Updated C'], $this->eventLog());
    }

    #[Test]
    #[TestDox('A forward reference waits while later independent operations run, as without a cycle')]
    public function keepsForwardReferenceOrder(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            $this->group('p', 'P', ['bulkId:q']),
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'x', 'data' => ['userName' => 'x']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'q', 'data' => ['userName' => 'q']],
        ]);

        self::assertSame(['p', 'x', 'q'], array_column($this->operations($response), 'bulkId'));
        self::assertSame(['Created x', 'Created q', 'Created P'], $this->eventLog());
    }

    #[Test]
    #[TestDox('A failed create of the cut POST keeps its error; the partner fails with 409')]
    public function failsCutCreate(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'bjensen', self::ENTERPRISE => ['manager' => ['value' => 'bulkId:b']]]],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'b', 'data' => ['userName' => 'bob', self::ENTERPRISE => ['manager' => ['value' => 'bulkId:a']]]],
        ]);

        self::assertSame([
            ['method' => 'POST', 'bulkId' => 'a', 'status' => '409', 'response' => ['schemas' => [self::ERROR], 'status' => '409', 'scimType' => 'uniqueness', 'detail' => 'The value of attribute "userName" is already in use.']],
            ['method' => 'POST', 'bulkId' => 'b', 'status' => '409', 'response' => ['schemas' => [self::ERROR], 'status' => '409', 'detail' => 'The POST operation with the bulkId "a" failed.']],
        ], $this->operations($response));
        self::assertSame([], $this->events);
    }

    #[Test]
    #[TestDox('A failed partner fails the cut POST with 409 and the location of its resource')]
    public function failsCompletionAfterFailedPartner(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            $this->group('a', 'A', ['bulkId:b']),
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'b', 'data' => ['members' => [['value' => 'bulkId:a']]]],
        ]);

        $a = $this->idOf($this->groups, 'displayName', 'A');
        self::assertSame([
            ['location' => self::BASE_URL.'/Groups/'.$a, 'method' => 'POST', 'bulkId' => 'a', 'status' => '409', 'response' => $this->uncompleted('the POST operation with the bulkId "b" failed')],
            ['method' => 'POST', 'bulkId' => 'b', 'status' => '400', 'response' => ['schemas' => [self::ERROR], 'status' => '400', 'scimType' => 'invalidValue', 'detail' => 'Attribute "displayName" is required.']],
        ], $this->operations($response));
        self::assertNull($this->members($a));
    }

    #[Test]
    #[TestDox('A failed PATCH fails the cut POST with its status and the location of its resource')]
    public function failsCompletionPatch(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'a', 'data' => ['displayName' => 'A', 'members' => [['value' => 'bulkId:b', 'type' => 5]]]],
            $this->group('b', 'B', ['bulkId:a']),
        ]);

        self::assertSame([
            'location' => self::BASE_URL.'/Groups/'.$this->idOf($this->groups, 'displayName', 'A'),
            'method' => 'POST',
            'bulkId' => 'a',
            'status' => '400',
            'response' => ['schemas' => [self::ERROR], 'status' => '400', 'scimType' => 'invalidValue', 'detail' => 'The resource was created, but its circular bulkId references were not added: Operations[0]: Attribute "urn:ietf:params:scim:schemas:core:2.0:Group:members.type" must be a string.'],
        ], $this->operations($response)[0] ?? null);
    }

    #[Test]
    #[TestDox('A deferred reference in a required attribute makes the next POST of the cycle the cut one')]
    public function cutsPostWithoutRequiredReference(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'bulkId:b']],
            $this->group('b', 'B', ['bulkId:a']),
        ]);

        $b = $this->idOf($this->groups, 'displayName', 'B');
        self::assertSame(['201', '201'], array_column($this->operations($response), 'status'));
        self::assertSame([['value' => $this->idOf($this->users, 'userName', $b)]], $this->members($b));
        self::assertSame(['Created B', 'Created '.$b, 'Updated B'], $this->eventLog());
    }

    #[Test]
    #[TestDox('When each POST of the cycle holds its reference in a required attribute, the first fails with 409')]
    public function failsUnbreakableCycle(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'bulkId:b']],
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'b', 'data' => ['userName' => 'bulkId:a']],
        ]);

        self::assertSame([
            ['method' => 'POST', 'bulkId' => 'a', 'status' => '409', 'response' => ['schemas' => [self::ERROR], 'status' => '409', 'detail' => 'Circular bulkId references (a -> b -> a) cannot be resolved, because each POST of the cycle references it in a required attribute.']],
            ['method' => 'POST', 'bulkId' => 'b', 'status' => '409', 'response' => ['schemas' => [self::ERROR], 'status' => '409', 'detail' => 'The POST operation with the bulkId "a" failed.']],
        ], $this->operations($response));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function referencesWithoutCompletion(): iterable
    {
        yield 'a readOnly attribute' => [['userName' => 'alice', 'groups' => [['value' => 'bulkId:g']]], ['bulkId:u']];
        yield 'an unknown attribute' => [['userName' => 'alice', 'buddy' => 'bulkId:g'], ['bulkId:u']];
    }

    /**
     * @param array<string, mixed> $user
     * @param list<string>         $members
     */
    #[Test]
    #[DataProvider('referencesWithoutCompletion')]
    #[TestDox('A reference in $_dataName is left out without a PATCH, as the create drops it')]
    public function cutsWithoutCompletion(array $user, array $members): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'u', 'data' => $user],
            $this->group('g', 'G', $members),
        ]);

        $alice = $this->idOf($this->users, 'userName', 'alice');
        self::assertSame([
            ['location' => self::BASE_URL.'/Users/'.$alice, 'method' => 'POST', 'bulkId' => 'u', 'version' => 'W/"1"', 'status' => '201'],
            ['location' => self::BASE_URL.'/Groups/'.$this->idOf($this->groups, 'displayName', 'G'), 'method' => 'POST', 'bulkId' => 'g', 'version' => 'W/"1"', 'status' => '201'],
        ], $this->operations($response));
        self::assertSame(['Created alice', 'Created G'], $this->eventLog());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function cutPostsThatFail(): iterable
    {
        yield 'path that does not route' => [['method' => 'POST', 'path' => '/Nope', 'bulkId' => 'a', 'data' => ['x' => 'bulkId:b']], 'The endpoint /Nope does not exist.'];
        yield 'data that is not an object' => [['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'a', 'data' => ['bulkId:b']], 'The request body must be a JSON object.'];
        yield 'extension that is not an object' => [['method' => 'POST', 'path' => '/Users', 'bulkId' => 'a', 'data' => ['userName' => 'alice', self::ENTERPRISE => 'bulkId:b']], \sprintf('Attribute "%s" must be a complex object.', self::ENTERPRISE)];
        yield 'reference to a failed POST' => [['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'a', 'data' => ['displayName' => 'A', 'members' => [['value' => 'bulkId:b'], ['value' => 'bulkId:x']]]], 'The POST operation with the bulkId "x" failed.'];
    }

    /**
     * @param array<string, mixed> $cut
     */
    #[Test]
    #[DataProvider('cutPostsThatFail')]
    #[TestDox('A cut POST with a $_dataName fails as the single request would, and creates nothing')]
    public function failsCutPost(array $cut, string $detail): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'x', 'data' => ['displayName' => 'No userName']],
            $cut,
            $this->group('b', 'B', ['bulkId:a']),
        ]);

        $operations = $this->operations($response);
        self::assertSame($detail, ($operations[1]['response'] ?? [])['detail'] ?? null);
        self::assertSame('The POST operation with the bulkId "a" failed.', ($operations[2]['response'] ?? [])['detail'] ?? null);
        self::assertSame([], $this->events);
    }

    #[Test]
    #[TestDox('failOnErrors 1: a failed partner stops the request; the cut POST is reported after the stop')]
    public function stopsAfterFailedPartner(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            $this->group('a', 'A', ['bulkId:b']),
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'b', 'data' => ['members' => [['value' => 'bulkId:a']]]],
            $this->group('c', 'C', ['bulkId:a']),
        ], ['failOnErrors' => 1]);

        self::assertSame(['a', 'b'], array_column($this->operations($response), 'bulkId'));
        self::assertSame($this->uncompleted('the POST operation with the bulkId "b" failed'), $this->operations($response)[0]['response'] ?? null);
    }

    #[Test]
    #[TestDox('failOnErrors 1: a failure before the partner runs reports the cut POST as stopped')]
    public function stopsBeforePartner(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            $this->group('a', 'A', ['bulkId:b']),
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'y', 'data' => ['members' => [['value' => 'bulkId:a']]]],
            $this->group('b', 'B', ['bulkId:a']),
        ], ['failOnErrors' => 1]);

        $operations = $this->operations($response);
        self::assertSame(['a', 'y'], array_column($operations, 'bulkId'));
        self::assertSame(['409', '400'], array_column($operations, 'status'));
        self::assertSame(self::BASE_URL.'/Groups/'.$this->idOf($this->groups, 'displayName', 'A'), $operations[0]['location'] ?? null);
        self::assertSame($this->uncompleted('failOnErrors stopped the bulk request'), $operations[0]['response'] ?? null);
    }

    #[Test]
    #[TestDox('failOnErrors 2: a failed PATCH counts as an error and stops the request')]
    public function countsFailedCompletion(): void
    {
        $server = $this->server();

        $response = $this->bulk($server, [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'f', 'data' => ['displayName' => 'No userName']],
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'a', 'data' => ['displayName' => 'A', 'members' => [['value' => 'bulkId:b', 'type' => 5]]]],
            $this->group('b', 'B', ['bulkId:a']),
            $this->group('z', 'Z', ['bulkId:b']),
        ], ['failOnErrors' => 2]);

        self::assertSame(['f', 'a', 'b'], array_column($this->operations($response), 'bulkId'));
        self::assertSame(['400', '400', '201'], array_column($this->operations($response), 'status'));
    }

    /**
     * @param list<string> $members the member values
     *
     * @return array<string, mixed>
     */
    private function group(string $bulkId, string $displayName, array $members): array
    {
        return ['method' => 'POST', 'path' => '/Groups', 'bulkId' => $bulkId, 'data' => [
            'displayName' => $displayName,
            'members' => array_map(static fn (string $value): array => ['value' => $value], $members),
        ]];
    }

    /**
     * @return array{schemas: list<string>, status: string, detail: string}
     */
    private function uncompleted(string $reason): array
    {
        return ['schemas' => [self::ERROR], 'status' => '409', 'detail' => \sprintf('The resource was created without its circular bulkId references, because %s.', $reason)];
    }

    private function members(string $groupId): mixed
    {
        return ($this->groups->get($groupId) ?? [])['members'] ?? null;
    }
}
