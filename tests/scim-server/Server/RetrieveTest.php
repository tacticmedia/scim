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
    #[TestDox('GET of an unknown id in any shape answers 404')]
    public function answersNotFound(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        $response = $this->send($server, 'GET', '/Users/00919288221112222');

        self::assertScimError($response, 404, null, 'Resource 00919288221112222 not found.');
    }

    #[Test]
    #[TestDox('GET with excludedAttributes=members omits the members of a group')]
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

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function ifNoneMatch(): iterable
    {
        yield 'the same weak ETag' => ['W/"1"', 304];
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
    #[TestDox('A client that accepts application/json and not application/scim+json gets application/json')]
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
