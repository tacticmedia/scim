<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Environment rate_limit: 2 requests per client, then 1 per hour.
 */
final class RateLimitTest extends WebTestCase
{
    use ScimClient;

    #[Test]
    #[TestDox('The third request of a client answers a SCIM 429 with an integer Retry-After of at least 1')]
    public function thirdRequestIsRejected(): void
    {
        $statuses = [$this->send('GET', '/scim/v2/Users')->getStatusCode(), $this->send('GET', '/scim/v2/ServiceProviderConfig')->getStatusCode()];
        $response = $this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'ada']);

        self::assertSame([200, 200], $statuses);
        self::assertScimError($response, 429, null, 'Too many requests.');
        self::assertMatchesRegularExpression('/^[1-9]\d*$/', (string) $response->headers->get('Retry-After'));
        self::assertSame(0, self::json($this->send('GET', '/scim/v2/Users', headers: Scim::OKTA))['totalResults'] ?? null);
    }

    #[Test]
    #[TestDox('Requests without a valid token answer 401, never 429, and leave the buckets of the clients full')]
    public function failedAuthenticationIsNotLimited(): void
    {
        $statuses = array_map(fn (array $headers): int => $this->send('GET', '/scim/v2/Users', headers: $headers)->getStatusCode(), [[], ['Authorization' => 'Bearer wrong'], [], Scim::ENTRA, Scim::ENTRA]);

        self::assertSame([401, 401, 401, 200, 200], $statuses);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'rate_limit'] + $options);
    }
}
