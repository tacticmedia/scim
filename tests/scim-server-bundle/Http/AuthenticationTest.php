<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use TacticMedia\ScimServerBundle\Tests\Fixtures\TestKernel;

/**
 * The firewall of docs/index.md: the bundle token handler, extractor and failure handler.
 */
final class AuthenticationTest extends WebTestCase
{
    use ScimClient;

    /**
     * @return iterable<string, array{0: string, 1?: string}>
     */
    public static function protectedUris(): iterable
    {
        yield 'an endpoint' => ['/scim/v2/Users'];
        yield 'the root without a trailing slash' => ['/scim/v2'];
        yield 'a discovery endpoint' => ['/scim/v2/ServiceProviderConfig'];
        yield 'an endpoint with OPTIONS' => ['/scim/v2/Users', 'OPTIONS'];
    }

    #[Test]
    #[DataProvider('protectedUris')]
    #[TestDox('No token on $_dataName answers a SCIM 401 with WWW-Authenticate: Bearer')]
    public function missingTokenIsRejected(string $uri, string $method = 'GET'): void
    {
        $response = $this->send($method, $uri, headers: []);

        self::assertScimError($response, 401, null, 'Full authentication is required to access this resource.');
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongTokens(): iterable
    {
        yield 'Bearer' => ['Bearer wrong-token'];
        yield 'bare' => ['wrong-token'];
        yield 'Bearer with a token of another client in another case' => ['Bearer '.strtoupper(TestKernel::OKTA_TOKEN)];
    }

    #[Test]
    #[DataProvider('wrongTokens')]
    #[TestDox('A wrong $_dataName token answers a SCIM 401 with error="invalid_token"')]
    public function wrongTokenIsRejected(string $authorization): void
    {
        $response = $this->send('GET', '/scim/v2/Users', headers: ['Authorization' => $authorization]);

        self::assertScimError($response, 401, null, 'Invalid credentials.');
        self::assertSame('Bearer error="invalid_token"', $response->headers->get('WWW-Authenticate'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function validTokens(): iterable
    {
        yield 'Entra, Bearer' => [Scim::ENTRA, 'entra'];
        yield 'Okta, bare (Header authentication)' => [Scim::OKTA, 'okta'];
        yield 'Okta, Bearer' => [['Authorization' => 'Bearer '.TestKernel::OKTA_TOKEN], 'okta'];
        yield 'Entra, bearer in lower case' => [['Authorization' => 'bearer '.TestKernel::ENTRA_TOKEN], 'entra'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('validTokens')]
    #[TestDox('$_dataName authenticates the client by name with ROLE_SCIM_CLIENT')]
    public function validTokenIsAccepted(array $headers, string $client): void
    {
        $response = $this->send('GET', '/scim/v2/Users', headers: $headers);
        $user = self::getContainer()->get('security.token_storage')->getToken()?->getUser();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$client, ['ROLE_SCIM_CLIENT']], [$user?->getUserIdentifier(), $user?->getRoles()]);
    }

    #[Test]
    #[TestDox('A method that the endpoint does not allow answers a SCIM 405 with the Allow of the endpoint')]
    public function unsupportedMethodIsRejected(): void
    {
        $response = $this->send('OPTIONS', '/scim/v2/Users');

        self::assertScimError($response, 405, null, 'The method OPTIONS is not allowed. Allowed: GET, HEAD, POST.');
        self::assertSame('GET, HEAD, POST', $response->headers->get('Allow'));
    }

    #[Test]
    #[TestDox('An error outside the SCIM route keeps the kernel error page')]
    public function otherRoutesKeepTheirErrors(): void
    {
        $response = $this->send('GET', '/other');

        self::assertSame(404, $response->getStatusCode());
        self::assertNotSame('application/scim+json', $response->headers->get('Content-Type'));
    }
}
