<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimDemo\IdentityProvider\Entity\ProvisioningLogEntry;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\ProvisioningFailed;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\ScimClient;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningLogEntryRepository;

final class ProvisioningFailureTest extends KernelTestCase
{
    use IdentityProviderKernel;

    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();
    }

    #[Test]
    #[TestDox('A non-2xx answer throws ProvisioningFailed with the SCIM detail and is logged without the token')]
    public function failsOnErrorStatus(): void
    {
        self::useSettings(Dialect::EntraDefault, 'wrong-token');
        $user = self::newUser('Ada', 'Lovelace');

        try {
            self::service(Provisioner::class)->syncUser($user);
            self::fail('ProvisioningFailed expected.');
        } catch (ProvisioningFailed $provisioningFailed) {
            self::assertSame('GET http://127.0.0.1:8001/scim/v2/Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22 answered 401: Authorization failure.', $provisioningFailed->getMessage());
        }

        $entries = self::service(ProvisioningLogEntryRepository::class)->newest(10);
        self::assertCount(1, $entries);
        self::assertSame(['Accept' => 'application/scim+json', 'Authorization' => 'Bearer ***'], $entries[0]->requestHeaders);
        self::assertSame(401, $entries[0]->status);
        self::assertFalse($entries[0]->succeeded());
        self::assertNull($user->scimId);
    }

    #[Test]
    #[TestDox('An unreachable service provider throws ProvisioningFailed and is logged with status 0')]
    public function failsWhenUnreachable(): void
    {
        self::useSettings(Dialect::Okta, serviceProviderUrl: 'http://127.0.0.1:9/scim/v2/');
        $user = self::newUser('Ada', 'Lovelace');

        try {
            self::service(Provisioner::class)->syncUser($user);
            self::fail('ProvisioningFailed expected.');
        } catch (ProvisioningFailed $provisioningFailed) {
            self::assertStringStartsWith('GET http://127.0.0.1:9/scim/v2/Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22&startIndex=1&count=100 did not reach the service provider: ', $provisioningFailed->getMessage());
        }

        $entry = self::service(ProvisioningLogEntryRepository::class)->newest(1)[0];
        self::assertSame(0, $entry->status);
        self::assertSame(['Accept' => 'application/scim+json', 'Authorization' => '***', 'User-Agent' => 'Okta SCIM Client 1.0.0'], $entry->requestHeaders);
    }

    #[Test]
    #[TestDox('An error without a SCIM detail names that')]
    public function reportsMissingDetail(): void
    {
        $entry = new ProvisioningLogEntry(new \DateTimeImmutable(), Dialect::Okta, 'GET', 'http://sp/Users', [], null, 500, 'Internal error');

        self::assertSame('GET http://sp/Users answered 500: no SCIM error detail', ProvisioningFailed::of($entry)->getMessage());
    }

    #[Test]
    #[TestDox('A resource without an id cannot be linked')]
    public function rejectsResourceWithoutId(): void
    {
        $this->expectExceptionObject(ProvisioningFailed::withoutId());

        ScimClient::id(['userName' => 'ada.lovelace@example.com']);
    }

    #[Test]
    #[TestDox('A full sync continues after a failure and returns the failure messages')]
    public function fullSyncCollectsFailures(): void
    {
        self::useSettings(Dialect::EntraDefault, serviceProviderUrl: 'http://127.0.0.1:9/scim/v2');
        self::newGroup('Engineering', self::newUser('Ada', 'Lovelace'), self::newUser('Grace', 'Hopper'));

        $failures = self::service(Provisioner::class)->syncAll();

        self::assertCount(3, $failures);
        self::assertStringContainsString('/Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22 did not reach', $failures[0]);
        self::assertStringContainsString('/Users?filter=userName%20eq%20%22grace.hopper%40example.com%22 did not reach', $failures[1]);
        self::assertStringContainsString('/Groups?excludedAttributes=members&filter=displayName%20eq%20%22Engineering%22 did not reach', $failures[2]);
    }

    #[Test]
    #[TestDox('A full sync provisions every user, then every group')]
    public function fullSyncProvisionsEverything(): void
    {
        $manager = self::newUser('Grace', 'Hopper');
        $group = self::newGroup('Engineering', self::newUser('Ada', 'Lovelace', $manager), $manager);

        self::assertSame([], self::service(Provisioner::class)->syncAll());

        self::assertSame([
            'GET', 'GET', 'POST',
            'GET', 'GET', 'POST', 'PATCH',
            'GET', 'POST', 'PATCH',
        ], array_map(static fn (array $request): string => strstr($request['line'], ' ', true) ?: '', self::fakeServiceProvider()->requests));
        self::assertCount(2, self::fakeServiceProvider()->group($group->scimId)['members'] ?? []);
    }
}
