<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The route prefix, the base path that the controller computes, and the discovery endpoints over HTTP.
 */
final class DiscoveryTest extends WebTestCase
{
    use ScimClient;

    #[Test]
    #[TestDox('/ServiceProviderConfig publishes the configuration under the route prefix')]
    public function servesTheConfiguration(): void
    {
        $config = self::json($this->send('GET', '/scim/v2/ServiceProviderConfig'));

        self::assertSame([true, 200, true, 'oauthbearertoken'], [$config['patch']['supported'] ?? null, $config['filter']['maxResults'] ?? null, $config['changePassword']['supported'] ?? null, $config['authenticationSchemes'][0]['type'] ?? null]);
        self::assertSame(['resourceType' => 'ServiceProviderConfig', 'location' => Scim::BASE_URL.'/ServiceProviderConfig'], $config['meta'] ?? null);
    }

    #[Test]
    #[TestDox('/ResourceTypes lists the mapped classes and the raw repository with their locations')]
    public function servesTheResourceTypes(): void
    {
        $types = self::resources($this->send('GET', '/scim/v2/ResourceTypes'));

        self::assertSame(['Badge', 'Group', 'User', 'Printer'], array_column($types, 'name'));
        self::assertSame(Scim::BASE_URL.'/ResourceTypes/User', $types[2]['meta']['location'] ?? null);
        self::assertSame([['schema' => Scim::ENTERPRISE, 'required' => false]], $types[2]['schemaExtensions'] ?? null);
    }

    #[Test]
    #[TestDox('/Schemas publishes only the mapped attributes of each schema')]
    public function servesTheSchemas(): void
    {
        $schemas = self::resources($this->send('GET', '/scim/v2/Schemas'));
        $group = array_find($schemas, static fn (array $schema): bool => Scim::GROUP === ($schema['id'] ?? null)) ?? [];

        self::assertSame(['urn:acme:scim:schemas:core:1.0:Badge', Scim::GROUP, Scim::USER, Scim::ENTERPRISE, 'urn:acme:scim:schemas:core:1.0:Printer', 'urn:acme:scim:schemas:extension:site:1.0:Printer', 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig', 'urn:ietf:params:scim:schemas:core:2.0:ResourceType', 'urn:ietf:params:scim:schemas:core:2.0:Schema'], array_column($schemas, 'id'));
        self::assertSame(['displayName', 'members'], array_column(\is_array($group['attributes'] ?? null) ? $group['attributes'] : [], 'name'));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function paths(): iterable
    {
        yield 'the root without a trailing slash' => ['/scim/v2', 200, Scim::LIST_RESPONSE];
        yield 'the root with a trailing slash' => ['/scim/v2/', 200, Scim::LIST_RESPONSE];
        yield 'an endpoint after a double slash (Entra Tenant URL with a trailing slash)' => ['/scim/v2//Users', 200, Scim::LIST_RESPONSE];
        yield 'an encoded slash in an id' => ['/scim/v2/Users/a%2Fb', 404, 'Resource a/b not found.'];
        yield 'an encoded space in an id' => ['/scim/v2/Users/a%20b', 404, 'Resource a b not found.'];
        yield 'an unknown endpoint' => ['/scim/v2/Devices', 404, 'The endpoint /scim/v2/Devices does not exist.'];
        yield 'an encoded slash in the prefix' => ['/scim%2Fv2/Users', 404, 'The endpoint /scim%2Fv2/Users does not exist.'];
        yield 'an encoded slash between the prefix and the endpoint' => ['/scim/v2%2FUsers', 404, 'The endpoint /scim/v2%2FUsers does not exist.'];
        yield 'an encoded slash in the prefix of a resource path' => ['/scim%2Fv2/Groups/Users/a', 404, 'The endpoint /scim%2Fv2/Groups/Users/a does not exist.'];
    }

    #[Test]
    #[DataProvider('paths')]
    #[TestDox('The base path is the route prefix for $_dataName')]
    public function basePathIsThePrefix(string $uri, int $status, string $expected): void
    {
        $response = $this->send('GET', $uri);
        $body = self::json($response);

        self::assertSame([$status, $expected], [$response->getStatusCode(), Scim::LIST_RESPONSE === $body['schemas'][0] ? Scim::LIST_RESPONSE : $body['detail'] ?? null]);
    }
}
