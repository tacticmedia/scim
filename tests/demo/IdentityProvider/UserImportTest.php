<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\UserImporter;

#[TestDox('User import')]
final class UserImportTest extends KernelTestCase
{
    use IdentityProviderKernel;

    private FakeServiceProvider $serviceProvider;

    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();
        $this->serviceProvider = self::fakeServiceProvider();
    }

    #[Test]
    #[TestDox('Keycloak pages by cursor: an empty cursor first, then each nextCursor until the last page')]
    public function pagesByCursor(): void
    {
        self::useSettings(Dialect::Keycloak);
        $this->seedServiceProvider();

        $users = self::service(UserImporter::class)->import(2);

        $nextCursor = $this->serviceProvider->requests[0]['response']['nextCursor'] ?? null;
        self::assertIsString($nextCursor);
        self::assertSame(['GET /Users?cursor&count=2', 'GET /Users?cursor='.$nextCursor.'&count=2'], array_column($this->serviceProvider->requests, 'line'));
        self::assertArrayNotHasKey('nextCursor', (array) $this->serviceProvider->requests[1]['response']);
        self::assertSame(['ada', 'grace', 'linus'], array_column($users, 'userName'));
    }

    #[Test]
    #[TestDox('Keycloak continues by startIndex when the first page has no nextCursor')]
    public function fallsBackToIndex(): void
    {
        self::useSettings(Dialect::Keycloak);
        $this->serviceProvider->cursors = false;
        $this->seedServiceProvider();

        $users = self::service(UserImporter::class)->import(2);

        self::assertSame(['ada', 'grace', 'linus'], array_column($users, 'userName'));
        self::assertSame(['GET /Users?cursor&count=2', 'GET /Users?startIndex=3&count=2'], array_column($this->serviceProvider->requests, 'line'));
    }

    #[Test]
    #[TestDox('The other dialects page by startIndex')]
    public function pagesByIndex(): void
    {
        self::useSettings(Dialect::Okta);
        $this->seedServiceProvider();

        $users = self::service(UserImporter::class)->import(2);

        self::assertSame(['ada', 'grace', 'linus'], array_column($users, 'userName'));
        self::assertSame(['GET /Users?startIndex=1&count=2', 'GET /Users?startIndex=3&count=2'], array_column($this->serviceProvider->requests, 'line'));
    }

    #[Test]
    #[TestDox('An empty service provider takes one request')]
    public function importsNothing(): void
    {
        self::useSettings(Dialect::Keycloak);
        $this->serviceProvider->cursors = false;

        self::assertSame([], self::service(UserImporter::class)->import(100));
        self::assertSame(['GET /Users?cursor&count=100'], array_column($this->serviceProvider->requests, 'line'));
    }

    private function seedServiceProvider(): void
    {
        foreach (['ada', 'grace', 'linus'] as $userName) {
            $this->serviceProvider->users->create(['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'userName' => $userName]);
        }
    }
}
