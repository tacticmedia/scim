<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServer\Tests\Server\Fixtures\RecordingRepository;

/**
 * The resolved attributes and excludedAttributes that the repository gets as hints.
 */
final class RepositoryHintsTest extends KernelTestCase
{
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const string ENTERPRISE_ATTRIBUTES = self::ENTERPRISE.':employeeNumber,'.self::ENTERPRISE.':costCenter,'.self::ENTERPRISE.':organization,'
        .self::ENTERPRISE.':division,'.self::ENTERPRISE.':department,'.self::ENTERPRISE.':manager';
    private const string PATCH_BODY = '{"schemas":["urn:ietf:params:scim:api:messages:2.0:PatchOp"],"Operations":[{"op":"add","path":"nickName","value":"Babs"}]}';

    private RecordingRepository $users;

    private RecordingRepository $groups;

    private ScimServer $server;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-11-05T10:00:00Z');
        $this->users = new RecordingRepository(new InMemoryResourceRepository(CoreResourceTypes::user(), $clock, [['schemas' => [self::USER], 'id' => 'u1', 'userName' => 'bjensen']]));
        $this->groups = new RecordingRepository(new InMemoryResourceRepository(CoreResourceTypes::group(), $clock, [['schemas' => [self::GROUP], 'id' => 'g1', 'displayName' => 'Tour Guides']]));
        $this->server = new ScimServer([
            new RegisteredResourceType(CoreResourceTypes::user(), $this->users),
            new RegisteredResourceType(CoreResourceTypes::group(), $this->groups),
        ]);
    }

    #[Test]
    #[TestDox('GET of a group with excludedAttributes=members passes the resolved path (Entra Q13)')]
    public function passesExcludedAttributes(): void
    {
        $this->send('GET', '/Groups/g1?excludedAttributes=members');

        self::assertSame(['g1 attributes= excludedAttributes='.self::GROUP.':members'], $this->groups->calls);
    }

    /**
     * @return iterable<string, array{string, string|null, list<string>}>
     */
    public static function userRequests(): iterable
    {
        yield 'GET with names in any case, without unknown names' => ['GET /Users/u1?attributes=USERNAME,shoeSize', null, ['u1 attributes='.self::USER.':userName excludedAttributes=']];
        yield 'GET with a whole extension URN' => ['GET /Users/u1?attributes='.self::ENTERPRISE, null, ['u1 attributes='.self::ENTERPRISE_ATTRIBUTES.' excludedAttributes=']];
        yield 'list with a whole extension URN' => ['GET /Users?excludedAttributes='.self::ENTERPRISE, null, ['list attributes= excludedAttributes='.self::ENTERPRISE_ATTRIBUTES]];
        yield 'root list' => ['GET /?attributes=id', null, ['list attributes='.self::USER.':id excludedAttributes=']];
        yield 'root list with sortBy: none, the merge reads the sortBy value' => ['GET /?sortBy=userName&attributes=id', null, ['list attributes= excludedAttributes=']];
        yield 'root list with sortBy and excludedAttributes' => ['GET /?sortBy=userName&excludedAttributes=userName', null, ['list attributes= excludedAttributes=']];
        yield 'PUT' => ['PUT /Users/u1?attributes=userName', '{"userName":"bjensen"}', ['u1 attributes= excludedAttributes=', 'list attributes= excludedAttributes=']];
        yield 'PATCH' => ['PATCH /Users/u1?attributes=userName', self::PATCH_BODY, ['u1 attributes= excludedAttributes=', 'list attributes= excludedAttributes=']];
        yield 'DELETE' => ['DELETE /Users/u1?attributes=userName', null, ['u1 attributes= excludedAttributes=']];
    }

    /**
     * @param list<string> $calls
     */
    #[Test]
    #[DataProvider('userRequests')]
    #[TestDox('Repository hints for $_dataName')]
    public function passesHintsOnlyForRetrieval(string $request, ?string $body, array $calls): void
    {
        [$method, $uri] = explode(' ', $request);

        $this->send($method, $uri, $body);

        self::assertSame($calls, $this->users->calls);
    }

    private function send(string $method, string $uri, ?string $body = null): void
    {
        $request = Request::create('https://example.com/scim/v2'.$uri, $method, server: ['CONTENT_TYPE' => 'application/scim+json'], content: $body);
        $response = $this->server->handle($request, '/scim/v2');

        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());
    }
}
