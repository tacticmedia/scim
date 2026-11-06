<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServerBundle\Controller\ScimController;

final class ScimControllerTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{Request, string, string}>
     */
    public static function requests(): iterable
    {
        yield 'a prefix with a tenant parameter' => [Request::create('http://localhost/scim/acme/v2/ServiceProviderConfig'), 'ServiceProviderConfig', 'http://localhost/scim/acme/v2/ServiceProviderConfig'];
        yield 'an encoded tenant' => [Request::create('http://localhost/scim/a%20b/v2/ServiceProviderConfig'), 'ServiceProviderConfig', 'http://localhost/scim/a%20b/v2/ServiceProviderConfig'];
        yield 'a front controller in the URL' => [Request::create('http://localhost/app/index.php/scim/v2/ServiceProviderConfig', server: ['SCRIPT_NAME' => '/app/index.php', 'SCRIPT_FILENAME' => '/var/www/app/index.php']), 'ServiceProviderConfig', 'http://localhost/app/index.php/scim/v2/ServiceProviderConfig'];
    }

    #[Test]
    #[DataProvider('requests')]
    #[TestDox('The base path follows the route for $_dataName')]
    public function basePathFollowsTheRoute(Request $request, string $path, string $location): void
    {
        $response = new ScimController(self::getContainer()->get('test.tacticmedia_scim_server.server'))($request, $path);

        self::assertSame($location, json_decode((string) $response->getContent(), true)['meta']['location'] ?? null);
    }
}
