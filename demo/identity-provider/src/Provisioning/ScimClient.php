<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TacticMedia\ScimDemo\IdentityProvider\Entity\ProvisioningLogEntry;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningSettingsRepository;

/**
 * Sends SCIM requests to the service provider of the current settings and logs each exchange. The caller flushes
 * the log entries.
 */
final readonly class ScimClient
{
    private const string MEDIA_TYPE = 'application/scim+json';

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private ProvisioningSettingsRepository $settingsRepository,
    ) {
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed> the response body, [] when it is empty
     *
     * @throws ProvisioningFailed when the answer is not 2xx
     */
    public function send(string $method, string $path, array $query = [], ?array $body = null): array
    {
        return $this->result($this->exchange($method, $path, $query, $body));
    }

    /**
     * A 404 counts as success: the service provider no longer has the resource.
     *
     * @param array<string, mixed>|null $body
     *
     * @throws ProvisioningFailed when the answer is neither 2xx nor 404
     */
    public function deprovision(string $method, string $path, ?array $body = null): void
    {
        $entry = $this->exchange($method, $path, [], $body);

        if (404 !== $entry->status) {
            $this->result($entry);
        }
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return array<mixed>|null null when $id is null or the service provider answers 404
     */
    public function get(string $endpoint, ?string $id, array $query = []): ?array
    {
        return null === $id ? null : $this->found('GET', $endpoint.'/'.rawurlencode($id), $query);
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>|null null when the service provider answers 404
     *
     * @throws ProvisioningFailed when the answer is neither 2xx nor 404
     */
    public function found(string $method, string $path, array $query = [], ?array $body = null): ?array
    {
        $entry = $this->exchange($method, $path, $query, $body);

        return 404 === $entry->status ? null : $this->result($entry);
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return array<mixed>|null the first resource of the list response
     */
    public function first(string $endpoint, array $query): ?array
    {
        $first = Json::object($this->send('GET', $endpoint, $query), 'Resources')[0] ?? null;

        return \is_array($first) ? $first : null;
    }

    /**
     * @param array<mixed> $resource
     */
    public static function id(array $resource): string
    {
        return Json::string($resource, 'id') ?? throw ProvisioningFailed::withoutId();
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $body
     */
    private function exchange(string $method, string $path, array $query, ?array $body): ProvisioningLogEntry
    {
        $settings = $this->settingsRepository->current();
        $url = rtrim($settings->serviceProviderUrl, '/').$path.([] === $query ? '' : '?'.http_build_query($query, encoding_type: \PHP_QUERY_RFC3986));
        $requestBody = null === $body ? null : json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $headers = ['Accept' => self::MEDIA_TYPE, ...$settings->dialect->headers($settings->token)];

        if (null !== $requestBody) {
            $headers['Content-Type'] = self::MEDIA_TYPE;
        }

        [$status, $responseBody] = $this->transmit($method, $url, $headers, $requestBody);
        $entry = new ProvisioningLogEntry($this->clock->now(), $settings->dialect, $method, $url, str_replace($settings->token, '***', $headers), $requestBody, $status, $responseBody);
        $this->entityManager->persist($entry);

        return $entry;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{int, string}
     */
    private function transmit(string $method, string $url, array $headers, ?string $body): array
    {
        try {
            $response = $this->httpClient->request($method, $url, ['headers' => $headers, 'body' => $body ?? '']);

            return [$response->getStatusCode(), $response->getContent(false)];
        } catch (TransportExceptionInterface $transportException) {
            return [0, $transportException->getMessage()];
        }
    }

    /**
     * @return array<mixed>
     */
    private function result(ProvisioningLogEntry $entry): array
    {
        if (!$entry->succeeded()) {
            throw ProvisioningFailed::of($entry);
        }

        return Json::decode($entry->responseBody) ?? [];
    }
}
