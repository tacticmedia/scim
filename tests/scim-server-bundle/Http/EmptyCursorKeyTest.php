<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use TacticMedia\ScimServerBundle\Tests\Fixtures\TestKernel;

/**
 * Environments empty_cursor_key and empty_kernel_secret: the cursor key is an environment variable with an empty value.
 */
final class EmptyCursorKeyTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emptyKeys(): iterable
    {
        yield 'pagination.cursor.key' => ['empty_cursor_key', 'The tacticmedia_scim_server option pagination.cursor.key is empty. Give it a value, or set pagination.cursor: false.'];
        yield 'the default kernel.secret' => ['empty_kernel_secret', 'A non-empty value for the parameter "kernel.secret" is required.'];
    }

    #[Test]
    #[DataProvider('emptyKeys')]
    #[TestDox('An empty cursor key from $_dataName fails with an error that names it')]
    public function emptyKeyFailsClearly(string $environment, string $error): void
    {
        $client = self::createClient(['environment' => $environment]);
        $client->catchExceptions(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($error);

        $client->request('GET', '/scim/v2/ServiceProviderConfig', server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestKernel::ENTRA_TOKEN]);
    }
}
