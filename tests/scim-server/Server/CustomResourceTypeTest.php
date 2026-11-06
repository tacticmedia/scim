<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;

/**
 * A resource type with an extension, unique attributes of several kinds, an immutable attribute and attributes
 * returned on request.
 */
final class CustomResourceTypeTest extends KernelTestCase
{
    private const string DEVICE = 'urn:example:scim:schemas:2.0:Device';
    private const string NETWORK = 'urn:example:scim:schemas:extension:2.0:Network';
    private const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    private const array STORED = [
        'schemas' => [self::DEVICE, self::NETWORK],
        'id' => 'd1',
        'serialNumber' => 'SN-1',
        'assetTag' => 7,
        'owner' => 'it',
        'pin' => '0000',
        'aliases' => ['printer'],
        self::NETWORK => ['hostname' => 'printer-1', 'vlan' => 10],
    ];

    private ScimServer $server;

    private InMemoryResourceRepository $devices;

    protected function setUp(): void
    {
        $type = $this->deviceType();
        $this->devices = new InMemoryResourceRepository($type, new MockClock('2026-11-05T10:00:00Z'), [self::STORED]);
        $this->server = new ScimServer([new RegisteredResourceType($type, $this->devices)]);
    }

    #[Test]
    #[TestDox('POST returns the "request" attributes that the body names, in the core schema and in an extension')]
    public function returnsRequestedAttributesOnCreate(): void
    {
        $response = $this->send('POST', '/Devices', ['serialNumber' => 'SN-2', 'pin' => '1234', self::NETWORK => ['hostname' => 'scanner', 'vlan' => 20]]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('1234', $this->json($response)['pin'] ?? null);
        self::assertSame(['hostname' => 'scanner', 'vlan' => 20], $this->json($response)[self::NETWORK] ?? null);
    }

    #[Test]
    #[TestDox('GET returns a "request" attribute only when the attributes parameter names it')]
    public function returnsRequestedAttributesOnRetrieve(): void
    {
        self::assertArrayNotHasKey('pin', $this->json($this->send('GET', '/Devices/d1')));
        self::assertSame('0000', $this->json($this->send('GET', '/Devices/d1?attributes=pin'))['pin'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function conflicts(): iterable
    {
        yield 'a case-exact global string' => [['serialNumber' => 'SN-1'], 'serialNumber'];
        yield 'an integer' => [['serialNumber' => 'SN-2', 'assetTag' => 7], 'assetTag'];
        yield 'an extension attribute' => [['serialNumber' => 'SN-2', self::NETWORK => ['hostname' => 'PRINTER-1']], self::NETWORK.':hostname'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('conflicts')]
    #[TestDox('POST answers 409 uniqueness for $_dataName in use')]
    public function rejectsDuplicates(array $body, string $label): void
    {
        $response = $this->send('POST', '/Devices', $body);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(\sprintf('The value of attribute "%s" is already in use.', $label), $this->json($response)['detail'] ?? null);
    }

    #[Test]
    #[TestDox('POST accepts a case-exact value in another case and a value of a multi-valued attribute in use')]
    public function acceptsDistinctValues(): void
    {
        self::assertSame(201, $this->send('POST', '/Devices', ['serialNumber' => 'sn-1', 'aliases' => ['printer']])->getStatusCode());
    }

    #[Test]
    #[TestDox('PUT that changes an immutable attribute answers 400 mutability')]
    public function rejectsImmutableChange(): void
    {
        $response = $this->send('PUT', '/Devices/d1', ['serialNumber' => 'SN-1', 'owner' => 'finance']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['scimType' => 'mutability', 'detail' => 'Attribute "owner" is immutable.'], array_intersect_key($this->json($response), ['scimType' => 0, 'detail' => 0]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, mixed}>
     */
    public static function requestedByPatch(): iterable
    {
        yield 'a path' => [['op' => 'replace', 'path' => 'pin', 'value' => '1234'], 'pin', '1234'];
        yield 'a key of a value without path' => [['op' => 'replace', 'value' => ['pin' => '1234']], 'pin', '1234'];
        yield 'an extension URN path' => [['op' => 'add', 'path' => self::NETWORK, 'value' => ['vlan' => 30]], self::NETWORK, ['vlan' => 30]];
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('requestedByPatch')]
    #[TestDox('PATCH with attributes returns the "request" attribute that $_dataName names')]
    public function returnsRequestedAttributesOnPatch(array $operation, string $key, mixed $expected): void
    {
        $response = $this->send('PATCH', '/Devices/d1?attributes=serialNumber', ['schemas' => [self::PATCH_OP], 'Operations' => [$operation]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($expected, $this->json($response)[$key] ?? null);
    }

    private function deviceType(): ResourceType
    {
        return new ResourceType('Device', '/Devices', new Schema(self::DEVICE, 'Device', 'Device', [
            new AttributeDefinition('serialNumber', required: true, caseExact: true, uniqueness: Uniqueness::Global),
            new AttributeDefinition('assetTag', AttributeType::Integer, uniqueness: Uniqueness::Server),
            new AttributeDefinition('owner', mutability: Mutability::Immutable),
            new AttributeDefinition('pin', returned: Returned::Request),
            new AttributeDefinition('aliases', multiValued: true, uniqueness: Uniqueness::Server),
        ]), [new SchemaExtension(new Schema(self::NETWORK, 'Network', 'Network', [
            new AttributeDefinition('hostname', uniqueness: Uniqueness::Server),
            new AttributeDefinition('vlan', AttributeType::Integer, returned: Returned::Request),
        ]))]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $uri, ?array $body = null): Response
    {
        $request = Request::create('https://example.com'.$uri, $method, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/scim+json');

        return $this->server->handle($request, '');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
