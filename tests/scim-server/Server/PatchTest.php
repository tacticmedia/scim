<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class PatchTest extends KernelTestCase
{
    use ServerFixture;
    private const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    #[Test]
    #[TestDox('PATCH applies the operations and answers 204 with the new ETag (Okta path-less replace)')]
    public function patchesResource(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen', ['active' => true])]);

        $response = $this->send($server, 'PATCH', '/Users/u1', self::patch(['op' => 'replace', 'value' => ['active' => false]]));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame('W/"2"', $response->headers->get('ETag'));
        self::assertFalse(($this->users->get('u1') ?? [])['active'] ?? null);
    }

    #[Test]
    #[TestDox('PATCH with attributes or excludedAttributes answers 200 with the projected resource (RFC 7644 3.5.2)')]
    public function answersResourceWhenProjectionRequested(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen')]);

        $response = $this->send($server, 'PATCH', '/Users/u1?excludedAttributes=meta', self::patch(['op' => 'Add', 'path' => 'nickName', 'value' => 'Babs']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('W/"2"', $response->headers->get('ETag'));
        self::assertSame(['schemas' => [self::USER], 'id' => 'u1', 'userName' => 'bjensen', 'nickName' => 'Babs'], self::json($response));
    }

    #[Test]
    #[TestDox('PATCH dispatches ResourceUpdated with the stored document, the new document and the PATCH request')]
    public function dispatchesResourceUpdated(): void
    {
        $stored = self::storedGroup('g1', 'Tour Guides', ['members' => [['value' => 'u1'], ['value' => 'u2']]]);
        $server = $this->server(groups: [$stored]);
        $body = self::patch(['op' => 'Remove', 'path' => 'members', 'value' => [['value' => 'u1']]]);

        $this->send($server, 'PATCH', '/Groups/g1', $body);

        self::assertSame([['value' => 'u2']], ($this->groups->get('g1') ?? [])['members'] ?? null);
        self::assertEquals([new ResourceUpdated(CoreResourceTypes::group(), $stored, (array) $this->groups->get('g1'), PatchRequest::fromArray($body))], $this->events);
    }

    #[Test]
    #[TestDox('PATCH that changes nothing writes nothing and keeps the ETag (RFC 7644 3.5.2.1)')]
    public function keepsUnchangedResource(): void
    {
        $stored = self::storedGroup('g1', 'Tour Guides');
        $server = $this->server(groups: [$stored]);

        $response = $this->send($server, 'PATCH', '/Groups/g1', self::patch(['op' => 'replace', 'value' => ['id' => 'g1', 'displayName' => 'Tour Guides']]));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('W/"1"', $response->headers->get('ETag'));
        self::assertSame($stored, $this->groups->get('g1'));
        self::assertSame([], $this->events);
    }

    #[Test]
    #[TestDox('PATCH remove of the extension URN removes the extension and its schema URN')]
    public function removesExtension(): void
    {
        $enterprise = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
        $server = $this->server([self::storedUser('u1', 'bjensen', ['schemas' => [self::USER, $enterprise], $enterprise => ['employeeNumber' => '701984']])]);

        $response = $this->send($server, 'PATCH', '/Users/u1', self::patch(['op' => 'remove', 'path' => $enterprise]));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame([self::USER], ($this->users->get('u1') ?? [])['schemas'] ?? null);
        self::assertArrayNotHasKey($enterprise, $this->users->get('u1') ?? []);
    }

    #[Test]
    #[TestDox('PATCH to a userName of another resource answers 409 uniqueness')]
    public function checksUniqueness(): void
    {
        $server = $this->server([self::storedUser('u1', 'bjensen'), self::storedUser('u2', 'jsmith')]);

        $response = $this->send($server, 'PATCH', '/Users/u1', self::patch(['op' => 'replace', 'path' => 'userName', 'value' => 'jsmith']));

        self::assertScimError($response, 409, 'uniqueness', 'The value of attribute "userName" is already in use.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>, int, string|null, string}>
     */
    public static function failures(): iterable
    {
        yield 'a body without the PatchOp URN' => [
            ['Operations' => [['op' => 'add', 'path' => 'nickName', 'value' => 'Babs']]],
            [],
            400,
            'invalidSyntax',
            '"schemas" must contain "urn:ietf:params:scim:api:messages:2.0:PatchOp".',
        ];
        yield 'a path that does not resolve' => [
            self::patch(['op' => 'replace', 'path' => 'shoeSize', 'value' => 42]),
            [],
            400,
            'invalidPath',
            "Operations[0]: The attribute 'shoeSize' is not defined for the resource type User.",
        ];
        yield 'a value that the normalizer rejects' => [
            self::patch(['op' => 'add', 'path' => 'nickName', 'value' => 'Babs'], ['op' => 'replace', 'path' => 'active', 'value' => 'maybe']),
            [],
            400,
            'invalidValue',
            'Operations[1]: Attribute "urn:ietf:params:scim:schemas:core:2.0:User:active" must be a boolean.',
        ];
        yield 'an If-Match that does not match' => [
            self::patch(['op' => 'add', 'path' => 'nickName', 'value' => 'Babs']),
            ['If-Match' => 'W/"0"'],
            412,
            null,
            'Failed to update. Resource has changed on the server.',
        ];
        yield 'an If-None-Match that matches (RFC 7232 section 3.2)' => [
            self::patch(['op' => 'add', 'path' => 'nickName', 'value' => 'Babs']),
            ['If-None-Match' => 'W/"1"'],
            412,
            null,
            'The resource matches If-None-Match.',
        ];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('failures')]
    #[TestDox('PATCH fails and stores nothing for $_dataName')]
    public function failsWithoutChange(array $body, array $headers, int $status, ?string $scimType, string $detail): void
    {
        $stored = self::storedUser('u1', 'bjensen');
        $server = $this->server([$stored]);

        $response = $this->send($server, 'PATCH', '/Users/u1', $body, $headers);

        self::assertScimError($response, $status, $scimType, $detail);
        self::assertSame($stored, $this->users->get('u1'));
    }

    #[Test]
    #[TestDox('PATCH of an unknown id answers 404')]
    public function answersNotFound(): void
    {
        $server = $this->server();

        self::assertScimError($this->send($server, 'PATCH', '/Users/u9', self::patch(['op' => 'add', 'path' => 'nickName', 'value' => 'Babs'])), 404, null, 'Resource u9 not found.');
    }

    /**
     * @param array<string, mixed> ...$operations
     *
     * @return array<string, mixed>
     */
    private static function patch(array ...$operations): array
    {
        return ['schemas' => [self::PATCH_OP], 'Operations' => $operations];
    }
}
