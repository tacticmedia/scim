<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;

final class ImportControllerTest extends WebTestCase
{
    use IdentityProviderKernel;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::createSchema();
    }

    #[Test]
    #[TestDox('"Import" pages through the users of the service provider and shows them with each request')]
    public function importsUsers(): void
    {
        self::useSettings(Dialect::Keycloak);

        foreach (['ada', 'grace', 'linus'] as $userName) {
            self::fakeServiceProvider()->users->create(['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'userName' => $userName, 'displayName' => ucfirst($userName)]);
        }

        $this->client->request('GET', '/');
        $form = $this->client->clickLink('Import')->selectButton('Import')->form();
        self::assertSame('100', $form->getValues()['count'] ?? null);
        $crawler = $this->client->submit($form, ['count' => '2']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.flash-success', 'Imported 3 users.');
        self::assertSame([['ada', 'Ada'], ['grace', 'Grace'], ['linus', 'Linus']], $crawler->filter('#users tbody tr')->each(static fn ($row): array => [$row->filter('td')->eq(1)->text(), $row->filter('td')->eq(2)->text()]));
        $requests = $crawler->filter('#requests li')->each(static fn ($item): string => $item->text());
        self::assertSame('GET http://127.0.0.1:8001/scim/v2/Users?cursor&count=2 200', $requests[0]);
        self::assertStringStartsWith('GET http://127.0.0.1:8001/scim/v2/Users?cursor=', $requests[1]);
        self::assertCount(2, $requests);
    }

    #[Test]
    #[TestDox('A failed import shows the failure')]
    public function reportsFailure(): void
    {
        self::useSettings(Dialect::EntraDefault, serviceProviderUrl: 'http://127.0.0.1:9/scim/v2');

        $crawler = $this->client->submit($this->client->request('GET', '/import')->selectButton('Import')->form());

        self::assertStringStartsWith('Import failed: GET http://127.0.0.1:9/scim/v2/Users?startIndex=1&count=100 did not reach the service provider', $crawler->filter('.flash-error')->text());
        self::assertCount(0, $crawler->filter('#users'));
    }
}
