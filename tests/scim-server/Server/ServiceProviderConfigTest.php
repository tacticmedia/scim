<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Server\AuthenticationScheme;
use TacticMedia\ScimServer\Server\ServiceProviderConfig;

final class ServiceProviderConfigTest extends KernelTestCase
{
    #[Test]
    #[TestDox('toArray() without options: maxResults 100, bulk with 1000 operations and 1 MiB, no password change, no documentation, no authentication schemes, index pagination only')]
    public function serializesDefaults(): void
    {
        self::assertSame([
            'patch' => ['supported' => true],
            'bulk' => ['supported' => true, 'maxOperations' => 1000, 'maxPayloadSize' => 1048576],
            'filter' => ['supported' => true, 'maxResults' => 100],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => true],
            'etag' => ['supported' => true],
            'authenticationSchemes' => [],
            'pagination' => ['cursor' => false, 'index' => true, 'defaultPaginationMethod' => 'index', 'defaultPageSize' => 100, 'maxPageSize' => 100],
        ], new ServiceProviderConfig()->toArray());
    }

    #[Test]
    #[TestDox('toArray() with every option, as RFC 7643 Figure 7 orders it, authentication schemes with type (erratum 8011)')]
    public function serializesOptions(): void
    {
        $config = new ServiceProviderConfig('https://example.com/help/scim.html', 200, true, [
            new AuthenticationScheme(
                'oauthbearertoken',
                'OAuth Bearer Token',
                'Authentication scheme using the OAuth Bearer Token Standard',
                'https://www.rfc-editor.org/info/rfc6750',
                'https://example.com/help/oauth.html',
                true,
            ),
            new AuthenticationScheme('httpbasic', 'HTTP Basic', 'Authentication scheme using the HTTP Basic Standard'),
        ], bulkMaxOperations: 10, bulkMaxPayloadSizeBytes: 2048);

        self::assertSame([
            'documentationUri' => 'https://example.com/help/scim.html',
            'patch' => ['supported' => true],
            'bulk' => ['supported' => true, 'maxOperations' => 10, 'maxPayloadSize' => 2048],
            'filter' => ['supported' => true, 'maxResults' => 200],
            'changePassword' => ['supported' => true],
            'sort' => ['supported' => true],
            'etag' => ['supported' => true],
            'authenticationSchemes' => [
                [
                    'type' => 'oauthbearertoken',
                    'name' => 'OAuth Bearer Token',
                    'description' => 'Authentication scheme using the OAuth Bearer Token Standard',
                    'specUri' => 'https://www.rfc-editor.org/info/rfc6750',
                    'documentationUri' => 'https://example.com/help/oauth.html',
                    'primary' => true,
                ],
                ['type' => 'httpbasic', 'name' => 'HTTP Basic', 'description' => 'Authentication scheme using the HTTP Basic Standard'],
            ],
            'pagination' => ['cursor' => false, 'index' => true, 'defaultPaginationMethod' => 'index', 'defaultPageSize' => 200, 'maxPageSize' => 200],
        ], $config->toArray());
    }

    #[Test]
    #[TestDox('toArray() with bulk turned off: bulk not supported, limits 0')]
    public function serializesBulkTurnedOff(): void
    {
        self::assertSame(['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0], new ServiceProviderConfig(bulkSupported: false, bulkMaxOperations: 10)->toArray()['bulk']);
    }

    #[Test]
    #[TestDox('toArray(true) publishes cursor pagination (RFC 9865 section 4), with cursorTimeout only when set')]
    public function serializesCursorPagination(): void
    {
        $withTimeout = new ServiceProviderConfig(maxResults: 50, cursorTimeoutSeconds: 600);

        self::assertSame(['cursor' => true, 'index' => true, 'defaultPaginationMethod' => 'index', 'defaultPageSize' => 50, 'maxPageSize' => 50, 'cursorTimeout' => 600], $withTimeout->toArray(true)['pagination']);
        self::assertArrayNotHasKey('cursorTimeout', $withTimeout->toArray()['pagination']);
        self::assertArrayNotHasKey('cursorTimeout', new ServiceProviderConfig()->toArray(true)['pagination']);
    }
}
