<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Server\AuthenticationScheme;
use TacticMedia\ScimServer\Server\ServiceProviderConfig;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class DiscoveryTest extends KernelTestCase
{
    use ServerFixture;
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const string LIST_RESPONSE = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    #[Test]
    #[TestDox('GET /ServiceProviderConfig answers the configuration with schemas and meta')]
    public function servesServiceProviderConfig(): void
    {
        $config = new ServiceProviderConfig(maxResults: 200, authenticationSchemes: [
            new AuthenticationScheme('oauthbearertoken', 'OAuth Bearer Token', 'Authentication scheme using the OAuth Bearer Token Standard', primary: true),
        ]);
        $server = $this->server(config: $config);

        $response = $this->send($server, 'GET', '/ServiceProviderConfig');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            ...$config->toArray(),
            'meta' => ['resourceType' => 'ServiceProviderConfig', 'location' => self::BASE_URL.'/ServiceProviderConfig'],
        ], self::json($response));
    }

    #[Test]
    #[TestDox('GET /ResourceTypes answers a ListResponse of the registered resource types')]
    public function listsResourceTypes(): void
    {
        $server = $this->server();

        $response = $this->send($server, 'GET', '/ResourceTypes?startIndex=2&count=1');

        self::assertSame([
            'schemas' => [self::LIST_RESPONSE],
            'totalResults' => 2,
            'itemsPerPage' => 2,
            'startIndex' => 1,
            'Resources' => [
                [
                    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                    ...CoreResourceTypes::user()->toArray(),
                    'meta' => ['resourceType' => 'ResourceType', 'location' => self::BASE_URL.'/ResourceTypes/User'],
                ],
                [
                    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                    ...CoreResourceTypes::group()->toArray(),
                    'meta' => ['resourceType' => 'ResourceType', 'location' => self::BASE_URL.'/ResourceTypes/Group'],
                ],
            ],
        ], self::json($response));
    }

    #[Test]
    #[TestDox('GET /ResourceTypes/{name} answers one resource type, with the name in any case')]
    public function servesResourceType(): void
    {
        $server = $this->server();

        $response = $this->send($server, 'GET', '/ResourceTypes/group');

        self::assertSame([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
            ...CoreResourceTypes::group()->toArray(),
            'meta' => ['resourceType' => 'ResourceType', 'location' => self::BASE_URL.'/ResourceTypes/Group'],
        ], self::json($response));
    }

    #[Test]
    #[TestDox('GET /Schemas answers a ListResponse with each schema of the resource types once, then the RFC 7643 Figure 10 schemas')]
    public function listsSchemas(): void
    {
        $server = $this->server();

        $response = $this->send($server, 'GET', '/Schemas');

        self::assertSame(6, self::json($response)['totalResults']);
        self::assertSame([
            self::USER,
            self::ENTERPRISE,
            self::GROUP,
            'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig',
            'urn:ietf:params:scim:schemas:core:2.0:ResourceType',
            'urn:ietf:params:scim:schemas:core:2.0:Schema',
        ], array_column((array) self::json($response)['Resources'], 'id'));
    }

    #[Test]
    #[TestDox('GET /Schemas/{urn} answers a Figure 10 schema with schemas and meta (erratum 8417)')]
    public function servesServiceProviderSchema(): void
    {
        $server = $this->server();

        $response = $this->send($server, 'GET', '/Schemas/urn:ietf:params:scim:schemas:core:2.0:Schema');

        self::assertSame([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Schema'],
            ...CoreSchemas::schema()->toArray(),
            'meta' => ['resourceType' => 'Schema', 'location' => self::BASE_URL.'/Schemas/urn:ietf:params:scim:schemas:core:2.0:Schema'],
        ], self::json($response));
    }

    #[Test]
    #[TestDox('GET /Schemas/{urn} answers one schema, with the URN in any case')]
    public function servesSchema(): void
    {
        $server = $this->server();

        $response = $this->send($server, 'GET', '/Schemas/'.strtoupper(self::ENTERPRISE));

        self::assertSame([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Schema'],
            ...CoreSchemas::enterpriseUser()->toArray(),
            'meta' => ['resourceType' => 'Schema', 'location' => self::BASE_URL.'/Schemas/'.self::ENTERPRISE],
        ], self::json($response));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unknownIds(): iterable
    {
        yield 'resource type' => ['/ResourceTypes/Device', 'Resource type Device not found.'];
        yield 'schema' => ['/Schemas/urn:example:Device', 'Schema urn:example:Device not found.'];
    }

    #[Test]
    #[DataProvider('unknownIds')]
    #[TestDox('An unknown $_dataName answers 404')]
    public function answersNotFound(string $path, string $detail): void
    {
        $server = $this->server();

        self::assertScimError($this->send($server, 'GET', $path), 404, null, $detail);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function discoveryEndpoints(): iterable
    {
        yield '/ServiceProviderConfig' => ['/ServiceProviderConfig'];
    }

    #[Test]
    #[DataProvider('discoveryEndpoints')]
    #[TestDox('A filter on $_dataName answers 403 (RFC 7644 section 4)')]
    public function refusesFilter(string $path): void
    {
        $server = $this->server();

        self::assertScimError($this->send($server, 'GET', $path.self::query(['Filter' => 'id eq "User"'])), 403, null, 'The discovery endpoints do not support filtering.');
    }
}
