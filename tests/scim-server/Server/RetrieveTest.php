<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class RetrieveTest extends KernelTestCase
{
    use ServerFixture;
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    #[Test]
    #[TestDox('GET of a resource answers 200 with the ETag header, the default attribute set and no null or empty values')]
    public function retrievesResource(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen', [
            'password' => 'secret',
            'name' => ['givenName' => 'Barbara', 'middleName' => null],
            'emails' => [],
            self::ENTERPRISE => ['employeeNumber' => '701984', 'manager' => []],
        ])]);

        $response = $this->send($server, 'GET', '/Users/u1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('W/"1"', $response->headers->get('ETag'));
        self::assertNull($response->headers->get('Location'));
        self::assertSame(
            '{"schemas":["urn:ietf:params:scim:schemas:core:2.0:User"],"id":"u1","meta":{"resourceType":"User","created":"2026-11-01T08:00:00Z","lastModified":"2026-11-01T08:00:00Z","version":"W/\"1\"","location":"https://example.com/scim/v2/Users/u1"},"userName":"bjensen","name":{"givenName":"Barbara"},"urn:ietf:params:scim:schemas:extension:enterprise:2.0:User":{"employeeNumber":"701984"}}',
            $response->getContent(),
        );
    }

    #[Test]
    #[TestDox('GET of an unknown id in any shape answers 404 (Okta quirk 21)')]
    public function answersNotFound(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        $response = $this->send($server, 'GET', '/Users/00919288221112222');

        self::assertScimError($response, 404, null, 'Resource 00919288221112222 not found.');
    }

    #[Test]
    #[TestDox('GET with excludedAttributes=members omits the members of a group (Entra Q13)')]
    public function excludesMembers(): void
    {
        $server = $this->server(groups: [self::storedGroup('g1', 'Tour Guides', ['members' => [['value' => 'u1', 'type' => 'User']]])]);

        $response = $this->send($server, 'GET', '/Groups/g1?excludedAttributes=members');

        self::assertArrayNotHasKey('members', self::json($response));
        self::assertSame('Tour Guides', self::json($response)['displayName']);
    }

    #[Test]
    #[TestDox('GET fills an absent "$ref" from "value" for each member type and keeps a stored one; the ETag is of the stored document')]
    public function fillsReferences(): void
    {
        $server = $this->server(groups: [['schemas' => [self::GROUP], 'id' => 'g1', 'displayName' => 'Tour Guides', 'members' => [
            ['value' => 'g2', 'type' => 'Group'],
            ['value' => 'u1', 'type' => 'User', '$ref' => 'https://idp.example.com/Users/u1'],
            ['value' => 'u2', 'type' => 'User'],
        ]]]);

        $response = $this->send($server, 'GET', '/Groups/g1');

        self::assertSame([
            ['value' => 'g2', 'type' => 'Group', '$ref' => self::BASE_URL.'/Groups/g2'],
            ['value' => 'u1', 'type' => 'User', '$ref' => 'https://idp.example.com/Users/u1'],
            ['value' => 'u2', 'type' => 'User', '$ref' => self::BASE_URL.'/Users/u2'],
        ], self::json($response)['members']);
        self::assertSame($response->headers->get('ETag'), self::json($response)['meta']['version'] ?? null);
    }

    #[Test]
    #[TestDox('GET fills manager.$ref (Entra reads the complex form)')]
    public function fillsManagerReference(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen'), self::storedUser('u2', 'jsmith', [self::ENTERPRISE => ['manager' => ['value' => 'u1']]])]);

        $response = $this->send($server, 'GET', '/Users/u2');

        self::assertSame([self::ENTERPRISE => ['manager' => ['value' => 'u1', '$ref' => self::BASE_URL.'/Users/u1']]], array_intersect_key(self::json($response), [self::ENTERPRISE => true]));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function extensionProjections(): iterable
    {
        yield 'attributes' => ['attributes', ['schemas', 'id', self::ENTERPRISE]];
        yield 'excludedAttributes' => ['excludedAttributes', ['schemas', 'id', 'meta', 'userName']];
    }

    /**
     * @param list<string> $keys
     */
    #[Test]
    #[DataProvider('extensionProjections')]
    #[TestDox('A whole extension URN in $_dataName selects every attribute of the extension')]
    public function projectsWholeExtension(string $parameter, array $keys): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen', [self::ENTERPRISE => ['employeeNumber' => '701984', 'department' => 'Tour Operations']])]);

        $response = self::json($this->send($server, 'GET', '/Users/u1'.self::query([$parameter => self::ENTERPRISE])));

        self::assertSame($keys, array_keys($response));
        self::assertSame(\in_array(self::ENTERPRISE, $keys, true) ? ['employeeNumber' => '701984', 'department' => 'Tour Operations'] : null, $response[self::ENTERPRISE] ?? null);
    }

    #[Test]
    #[TestDox('A document without meta.version gets a weak ETag computed from the document, the same on every GET')]
    public function computesETag(): void
    {
        $server = $this->server([['schemas' => [self::USER], 'id' => 'u1', 'userName' => 'bjensen']]);

        $first = $this->send($server, 'GET', '/Users/u1');
        $second = $this->send($server, 'GET', '/Users/u1');

        $etag = (string) $first->headers->get('ETag');
        self::assertMatchesRegularExpression('/\AW\/"[0-9a-f]{32}"\z/', $etag);
        self::assertSame($etag, $second->headers->get('ETag'));
        self::assertSame(['resourceType' => 'User', 'location' => self::BASE_URL.'/Users/u1', 'version' => $etag], self::json($first)['meta']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function ifNoneMatch(): iterable
    {
        yield 'the same weak ETag' => ['W/"1"', 304];
        yield 'the opaque tag without W/' => ['"1"', 304];
        yield 'one of several ETags' => ['W/"0", W/"1"', 304];
        yield 'any ETag' => ['*', 304];
        yield 'another ETag' => ['W/"2"', 200];
    }

    #[Test]
    #[DataProvider('ifNoneMatch')]
    #[TestDox('GET with If-None-Match: $_dataName')]
    public function answersIfNoneMatch(string $ifNoneMatch, int $status): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        $response = $this->send($server, 'GET', '/Users/u1', headers: ['If-None-Match' => $ifNoneMatch]);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('W/"1"', $response->headers->get('ETag'));
        self::assertSame(304 === $status, '' === $response->getContent());
    }

    /**
     * @return iterable<string, array{array<string, string>, int}>
     */
    public static function ifMatch(): iterable
    {
        yield 'the current ETag' => [['If-Match' => 'W/"1"'], 200];
        yield 'any ETag' => [['If-Match' => '*'], 200];
        yield 'another ETag' => [['If-Match' => 'W/"2"'], 412];
        yield 'another ETag and a matching If-None-Match' => [['If-Match' => 'W/"2"', 'If-None-Match' => 'W/"1"'], 412];
        yield 'the current ETag and a matching If-None-Match' => [['If-Match' => 'W/"1"', 'If-None-Match' => 'W/"1"'], 304];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('ifMatch')]
    #[TestDox('GET with If-Match: $_dataName (RFC 7232 sections 3.1 and 6)')]
    public function answersIfMatch(array $headers, int $status): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        self::assertSame($status, $this->send($server, 'GET', '/Users/u1', headers: $headers)->getStatusCode());
    }

    #[Test]
    #[TestDox('GET with an If-Match that does not match answers 412 with a SCIM error')]
    public function answersPreconditionFailed(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        self::assertScimError($this->send($server, 'GET', '/Users/u1', headers: ['If-Match' => '"nope"']), 412, null, 'The resource does not match If-Match.');
    }

    #[Test]
    #[TestDox('A client that accepts application/json and not application/scim+json gets application/json (quirk 35)')]
    public function negotiatesContentType(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        self::assertSame('application/json', $this->send($server, 'GET', '/Users/u1', headers: ['Accept' => 'Application/JSON; charset=utf-8'])->headers->get('Content-Type'));
        self::assertSame('application/scim+json', $this->send($server, 'GET', '/Users/u1', headers: ['Accept' => 'application/json, application/scim+json'])->headers->get('Content-Type'));
        self::assertSame('application/scim+json', $this->send($server, 'GET', '/Users/u1', headers: ['Accept' => '*/*'])->headers->get('Content-Type'));
    }

    #[Test]
    #[TestDox('JSON output keeps slashes and non-ASCII characters unescaped')]
    public function encodesJson(): void
    {
        $server = $this->server([self::storedUser('u1', 'Zoë/Ørsted', ['profileUrl' => 'https://example.com/zoë'])]);

        $content = (string) $this->send($server, 'GET', '/Users/u1?attributes=userName,profileUrl')->getContent();

        self::assertStringContainsString('"userName":"Zoë/Ørsted","profileUrl":"https://example.com/zoë"', $content);
    }
}
