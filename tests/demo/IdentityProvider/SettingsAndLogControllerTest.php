<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningSettingsRepository;

final class SettingsAndLogControllerTest extends WebTestCase
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
    #[TestDox('Saving the settings stores them')]
    public function savesSettings(): void
    {
        $this->client->request('GET', '/settings');
        $this->client->submitForm('Save', ['settings[serviceProviderUrl]' => 'http://localhost:8001/scim/v2', 'settings[token]' => 'other', 'settings[dialect]' => 'okta']);
        $this->client->followRedirect();

        self::assertSelectorTextSame('.flash-success', 'Settings saved.');
        $settings = self::service(ProvisioningSettingsRepository::class)->current();
        self::assertSame(['http://localhost:8001/scim/v2', 'other', Dialect::Okta], [$settings->serviceProviderUrl, $settings->token, $settings->dialect]);
    }

    #[Test]
    #[TestDox('Settings with an invalid URL are not saved')]
    public function rejectsInvalidSettings(): void
    {
        $this->client->request('GET', '/settings');
        $this->client->submitForm('Save', ['settings[serviceProviderUrl]' => 'not a url']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::service(ProvisioningSettingsRepository::class)->count());
    }

    #[Test]
    #[TestDox('"Provision everything" runs a full sync and the log shows each formatted request and response')]
    public function provisionsEverything(): void
    {
        self::newUser('Ada', 'Lovelace');

        $this->client->submit($this->client->request('GET', '/')->selectButton('Provision everything')->form());

        self::assertResponseRedirects('/log');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('.flash-success', 'Full sync finished with 0 failures.');
        self::assertSame(
            ['POST http://127.0.0.1:8001/scim/v2/Users', 'GET http://127.0.0.1:8001/scim/v2/Users?filter=externalId%20eq%20%22ada.lovelace%22', 'GET http://127.0.0.1:8001/scim/v2/Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22'],
            $crawler->filter('tbody td code')->each(static fn ($code): string => $code->text()),
        );
        self::assertSame(['201', '200', '200'], $crawler->filter('tbody strong')->each(static fn ($status): string => $status->text()));
        self::assertStringContainsString("Accept: application/scim+json\nAuthorization: Bearer ***\nContent-Type: application/scim+json", $crawler->filter('tbody pre')->eq(0)->text(normalizeWhitespace: false));
        self::assertStringContainsString("\n    \"userName\": \"ada.lovelace@example.com\",\n", $crawler->filter('tbody pre')->eq(1)->text(normalizeWhitespace: false));
    }

    #[Test]
    #[TestDox('"Provision everything" reports each failure')]
    public function reportsFullSyncFailures(): void
    {
        self::useSettings(Dialect::EntraDefault, serviceProviderUrl: 'http://127.0.0.1:9/scim/v2');
        self::newUser('Ada', 'Lovelace');

        $this->client->submit($this->client->request('GET', '/')->selectButton('Provision everything')->form());
        $crawler = $this->client->followRedirect();

        self::assertSame('Full sync finished with 1 failures.', $crawler->filter('.flash-error')->eq(0)->text());
        self::assertStringContainsString('did not reach the service provider', $crawler->filter('.flash-error')->eq(1)->text());
        self::assertSame('not reached', $crawler->filter('tbody strong.failed')->text());
    }

    #[Test]
    #[TestDox('The empty log says so')]
    public function showsEmptyLog(): void
    {
        $this->client->request('GET', '/log');

        self::assertSelectorTextSame('tbody', 'No requests yet.');
    }
}
