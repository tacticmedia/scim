<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServer\Event\ResourceDeleted;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Server\MeResolver;
use TacticMedia\ScimServer\Server\MeSubject;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServer\Server\ServiceProviderConfig;
use TacticMedia\ScimServer\Tests\Server\Fixtures\FixedMeResolver;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

/**
 * /Me with a MeResolver (RFC 7644 section 3.11): the server processes the request directly.
 */
final class MeTest extends KernelTestCase
{
    use ServerFixture;
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const string LOCATION = self::BASE_URL.'/Users/u1';
    private const string NO_RESOURCE = 'The authenticated subject has no resource.';

    #[Test]
    #[TestDox('GET /Me answers the resource as GET /Users/{id} does, with Location')]
    public function retrieves(): void
    {
        $server = $this->meServer(new MeSubject('User', 'u1'));

        $response = $this->send($server, 'GET', '/Me');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::json($this->send($server, 'GET', '/Users/u1')), self::json($response));
        self::assertSame([self::LOCATION, 'W/"1"'], [$response->headers->get('Location'), $response->headers->get('ETag')]);
        self::assertSame(self::LOCATION, self::json($response)['meta']['location'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null, int}>
     */
    public static function aliasedRequests(): iterable
    {
        $patch = ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'add', 'path' => 'nickName', 'value' => 'Babs']]];

        yield 'HEAD' => ['HEAD', '/Me', null, 200];
        yield 'PUT' => ['PUT', '/Me', ['userName' => 'babs'], 200];
        yield 'PATCH' => ['PATCH', '/Me', $patch, 204];
        yield '/me in lower case' => ['GET', '/me', null, 200];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('aliasedRequests')]
    #[TestDox('$_dataName acts on the resource and answers with Location')]
    public function actsOnTheResource(string $method, string $path, ?array $body, int $status): void
    {
        $server = $this->meServer(new MeSubject('User', 'u1'));

        $response = $this->send($server, $method, $path, $body);

        self::assertSame([$status, self::LOCATION], [$response->getStatusCode(), $response->headers->get('Location')]);
    }

    #[Test]
    #[TestDox('DELETE /Me deletes the resource and dispatches ResourceDeleted')]
    public function deletes(): void
    {
        $server = $this->meServer(new MeSubject('User', 'u1'));

        $this->send($server, 'DELETE', '/Me');

        self::assertSame(404, $this->send($server, 'GET', '/Users/u1')->getStatusCode());
        self::assertInstanceOf(ResourceDeleted::class, $this->events[0] ?? null);
    }

    #[Test]
    #[TestDox('GET /Me answers 404 without Location when the subject has no resource')]
    public function answersNotFoundWithoutSubject(): void
    {
        $server = $this->meServer(null);

        $response = $this->send($server, 'GET', '/Me');

        self::assertScimError($response, 404, null, self::NO_RESOURCE);
        self::assertFalse($response->headers->has('Location'));
    }

    #[Test]
    #[TestDox('POST /Me answers 501 with a resolver')]
    public function refusesPost(): void
    {
        $server = $this->meServer(new MeSubject('User', 'u1'));

        self::assertScimError($this->send($server, 'POST', '/Me', ['userName' => 'x']), 501, null, 'POST /Me is not supported.');
    }

    #[Test]
    #[TestDox('An id that does not exist answers 404 with Location')]
    public function answersNotFoundForUnknownId(): void
    {
        $server = $this->meServer(new MeSubject('User', 'a/b'));

        $response = $this->send($server, 'GET', '/Me');

        self::assertScimError($response, 404, null, 'Resource a/b not found.');
        self::assertSame(self::BASE_URL.'/Users/a%2Fb', $response->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lookups(): iterable
    {
        yield 'userName in another case' => ['userName', 'BJENSEN'];
        yield 'emails.value' => ['emails.value', 'b@example.com'];
    }

    #[Test]
    #[DataProvider('lookups')]
    #[TestDox('Finds the resource by $_dataName')]
    public function findsByAttribute(string $attribute, string $value): void
    {
        $server = $this->meServer(new MeSubject('User', $value, $attribute));

        self::assertSame(self::LOCATION, $this->send($server, 'GET', '/Me')->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function failedLookups(): iterable
    {
        yield 'no match' => ['nobody', 404, self::NO_RESOURCE];
        yield 'filter syntax in the value' => ['x" or userName pr or "', 404, self::NO_RESOURCE];
        yield 'two matches' => ['Twin', 409, 'The authenticated subject matches more than one resource.'];
    }

    #[Test]
    #[DataProvider('failedLookups')]
    #[TestDox('A lookup with $_dataName answers $status')]
    public function rejectsFailedLookup(string $value, int $status, string $detail): void
    {
        $server = $this->meServer(new MeSubject('User', $value, 'displayName'));

        self::assertScimError($this->send($server, 'GET', '/Me'), $status, null, $detail);
    }

    /**
     * @return iterable<string, array{MeSubject, string}>
     */
    public static function invalidSubjects(): iterable
    {
        yield 'unknown resource type' => [new MeSubject('Device', 'd1'), 'The MeResolver names the resource type "Device", which is not registered.'];
        yield 'unknown attribute' => [new MeSubject('User', 'x', 'shoeSize'), 'The MeResolver names the attribute "shoeSize", which the resource type User cannot filter.'];
        yield 'invalid attribute path' => [new MeSubject('User', 'x', 'not a path'), 'The MeResolver names the attribute "not a path", which the resource type User cannot filter.'];
    }

    #[Test]
    #[DataProvider('invalidSubjects')]
    #[TestDox('A subject with an $_dataName is a programming error')]
    public function rejectsInvalidSubject(MeSubject $subject, string $message): void
    {
        $server = $this->meServer($subject);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->send($server, 'GET', '/Me');
    }

    #[Test]
    #[TestDox('A ScimException of the resolver becomes the response')]
    public function answersResolverError(): void
    {
        $server = $this->server(me: new class implements MeResolver {
            #[\Override]
            public function resolve(Request $request): ?MeSubject
            {
                throw new ScimException(403, 'End users cannot use /Me.');
            }
        });

        self::assertScimError($this->send($server, 'GET', '/Me'), 403, null, 'End users cannot use /Me.');
    }

    private function meServer(?MeSubject $subject): ScimServer
    {
        return $this->server([
            self::storedUser('u1', 'bjensen', ['externalId' => 'ext-1', 'displayName' => 'Twin', 'emails' => [['value' => 'b@example.com']], self::ENTERPRISE => ['employeeNumber' => '701984']]),
            self::storedUser('u2', 'jsmith', ['displayName' => 'Twin']),
        ], config: new ServiceProviderConfig(), me: new FixedMeResolver($subject));
    }
}
