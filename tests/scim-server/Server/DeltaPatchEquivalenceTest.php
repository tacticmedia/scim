<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServer\Tests\Server\Fixtures\MemberDeltaRepository;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

/**
 * The same PATCH against a repository without deltas and against one that changes "members" by delta gives the same
 * response and the same stored group.
 */
final class DeltaPatchEquivalenceTest extends KernelTestCase
{
    use ServerFixture;

    /**
     * @return iterable<string, array{list<array<string, mixed>>, bool, 2?: array<string, string>}>
     */
    public static function requests(): iterable
    {
        yield 'Entra add' => [[['op' => 'Add', 'path' => 'members', 'value' => [['$ref' => null, 'value' => 'u3']]]], true];
        yield 'Entra add of a member' => [[['op' => 'Add', 'path' => 'members', 'value' => [['$ref' => null, 'value' => 'u1']]]], true];
        yield 'Entra add of two members' => [[['op' => 'Add', 'path' => 'members', 'value' => [['value' => 'u3'], ['value' => 'u2']]]], true];
        yield 'Entra remove with a value list' => [[['op' => 'Remove', 'path' => 'members', 'value' => [['value' => 'u2']]]], true];
        yield 'Entra remove with "$ref": null' => [[['op' => 'Remove', 'path' => 'members', 'value' => [['$ref' => null, 'value' => 'u2']]]], true];
        yield 'Keycloak add of a chunk' => [[['op' => 'add', 'path' => 'members', 'value' => array_map(static fn (int $i): array => ['value' => 'u'.$i], range(1, 100))]], true];
        yield 'Keycloak remove with a value array' => [[['op' => 'remove', 'path' => 'members', 'value' => [['value' => 'u1'], ['value' => 'u2'], ['value' => 'u9']]]], true];
        yield 'Okta push with display' => [[['op' => 'replace', 'path' => 'members', 'value' => [['value' => 'u2', 'display' => 'Babs'], ['value' => 'u3', 'display' => 'Mandy']]]], true];
        yield 'remove then add of the same member' => [[['op' => 'remove', 'path' => 'members[value eq "u1"]'], ['op' => 'add', 'path' => 'members', 'value' => 'u1']], true];
        yield 'Entra flag-style remove' => [[['op' => 'Remove', 'path' => 'members[value eq "u1"]']], true];
        yield 'a remove filter that matches nothing' => [[['op' => 'remove', 'path' => 'members[value eq "u9"]']], true];
        yield 'Okta remove plus add' => [[['op' => 'remove', 'path' => 'members[value eq "u2"]'], ['op' => 'add', 'path' => 'members', 'value' => [['value' => 'u3', 'display' => 'Babs']]]], true];
        yield 'Okta replace' => [[['op' => 'replace', 'path' => 'members', 'value' => [['value' => 'u3'], ['value' => 'u1']]]], true];
        yield 'Okta rename that changes nothing' => [[['op' => 'replace', 'value' => ['id' => 'g1', 'displayName' => 'Tour Guides']]], true];
        yield 'rename and add' => [[['op' => 'replace', 'path' => 'displayName', 'value' => 'Guides'], ['op' => 'add', 'path' => 'members', 'value' => 'u3']], true];
        yield 'remove without value' => [[['op' => 'remove', 'path' => 'members']], true];
        yield 'replace with []' => [[['op' => 'replace', 'path' => 'members', 'value' => []]], true];
        yield 'replace with ""' => [[['op' => 'replace', 'path' => 'members', 'value' => '']], true];
        yield 'add with []' => [[['op' => 'add', 'path' => 'members', 'value' => []]], true];
        yield 'add with null' => [[['op' => 'add', 'path' => 'members', 'value' => null]], true];
        yield 'remove with []' => [[['op' => 'remove', 'path' => 'members', 'value' => []]], true];
        yield 'remove with ""' => [[['op' => 'remove', 'path' => 'members', 'value' => '']], true];
        yield 'a filter on type' => [[['op' => 'remove', 'path' => 'members[type eq "User"]']], false];
        yield 'a member value that is not a string' => [[['op' => 'add', 'path' => 'members', 'value' => [['value' => 42]]]], false];
        yield 'a path that does not resolve' => [[['op' => 'add', 'path' => 'members', 'value' => 'u3'], ['op' => 'replace', 'path' => 'shoeSize', 'value' => 42]], false];
        yield 'a failed operation after a member add' => [[['op' => 'add', 'path' => 'members', 'value' => 'u3'], ['op' => 'remove', 'path' => 'displayName']], true];
        yield 'an If-Match that matches' => [[['op' => 'add', 'path' => 'members', 'value' => 'u3']], true, ['If-Match' => 'W/"1"']];
        yield 'an If-Match that does not match' => [[['op' => 'add', 'path' => 'members', 'value' => 'u3']], true, ['If-Match' => 'W/"7"']];
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, string>      $headers
     */
    #[Test]
    #[DataProvider('requests')]
    #[TestDox('$_dataName gives the same result on both paths')]
    public function givesSameResult(array $operations, bool $viaDelta, array $headers = []): void
    {
        $body = ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => $operations];
        $full = $this->groupServer($this->storedGroups());
        $deltas = new MemberDeltaRepository($this->storedGroups(), [new AttributePath(self::GROUP, 'members')]);
        $delta = $this->groupServer($deltas);

        self::assertSame(
            $this->outcome($this->send($full, 'PATCH', '/Groups/g1', $body, $headers), $this->send($full, 'GET', '/Groups/g1')),
            $this->outcome($this->send($delta, 'PATCH', '/Groups/g1', $body, $headers), $this->send($delta, 'GET', '/Groups/g1')),
        );
        self::assertSame($viaDelta ? 'g1 excludedAttributes='.self::GROUP.':members' : 'g1 excludedAttributes=', $deltas->gets[0] ?? null);
    }

    private function storedGroups(): InMemoryResourceRepository
    {
        return new InMemoryResourceRepository(CoreResourceTypes::group(), new MockClock('2026-11-05T10:00:00Z'), [
            self::storedGroup('g1', 'Tour Guides', ['members' => [['value' => 'u1', 'type' => 'User'], ['value' => 'u2']]]),
        ]);
    }

    private function groupServer(ResourceRepository $groups): ScimServer
    {
        return new ScimServer([new RegisteredResourceType(CoreResourceTypes::group(), $groups)]);
    }

    /**
     * @return array{int, string|null, string|false, string|false}
     */
    private function outcome(Response $patch, Response $get): array
    {
        return [$patch->getStatusCode(), $patch->headers->get('ETag'), $patch->getContent(), $get->getContent()];
    }
}
