<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\ProvisioningFailed;

#[TestDox('Keycloak')]
final class KeycloakProvisioningTest extends KernelTestCase
{
    use IdentityProviderKernel;
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    private const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    private Provisioner $provisioner;

    private FakeServiceProvider $serviceProvider;

    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();
        self::useSettings(Dialect::Keycloak);
        $this->provisioner = self::service(Provisioner::class);
        $this->serviceProvider = self::fakeServiceProvider();
    }

    #[Test]
    #[TestDox('Create: /ServiceProviderConfig, userName filter, then POST without password, phone and manager; Bearer token')]
    public function createsUser(): void
    {
        $manager = self::newUser('Grace', 'Hopper');
        $manager->scimId = 'grace-at-the-service-provider';

        $user = self::newUser('Ada', 'Lovelace', $manager);
        $user->mobilePhone = '555';
        $user->employeeNumber = 'E001';
        $user->demoPlainTextPassword = 'secret';

        $this->provisioner->syncUser($user);

        self::assertRequests([
            ['GET /ServiceProviderConfig', null],
            ['GET /Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22', null],
            ['POST /Users', [
                'schemas' => [self::USER, self::ENTERPRISE],
                'externalId' => $this->keycloakId($user),
                'userName' => 'ada.lovelace@example.com',
                'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace'],
                'displayName' => 'Ada Lovelace',
                'emails' => [['value' => 'ada.lovelace@example.com', 'type' => 'work', 'primary' => true]],
                'active' => true,
                self::ENTERPRISE => ['employeeNumber' => 'E001', 'department' => 'Engineering'],
            ]],
        ]);
        self::assertSame(['accept' => 'application/scim+json', 'authorization' => 'Bearer demo-token', 'content-type' => 'application/scim+json'], $this->serviceProvider->requests[2]['headers']);
        self::assertSame($user->scimId, $this->serviceProvider->requests[2]['response']['id'] ?? null);
    }

    #[Test]
    #[TestDox('Update: one PATCH that replaces every mapped attribute, the enterprise extension by its URN')]
    public function patchesUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($user);
        $this->serviceProvider->forgetRequests();
        $user->familyName = 'King';
        $user->workEmail = null;
        $user->active = false;

        $this->provisioner->syncUser($user);

        self::assertRequests([
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [
                ['op' => 'replace', 'path' => 'userName', 'value' => 'ada.lovelace@example.com'],
                ['op' => 'replace', 'path' => 'externalId', 'value' => $this->keycloakId($user)],
                ['op' => 'replace', 'path' => 'active', 'value' => false],
                ['op' => 'replace', 'path' => 'displayName', 'value' => 'Ada King'],
                ['op' => 'replace', 'path' => 'name', 'value' => ['givenName' => 'Ada', 'familyName' => 'King']],
                ['op' => 'replace', 'path' => self::ENTERPRISE, 'value' => ['department' => 'Engineering']],
            ]]],
        ]);
        $stored = $this->serviceProvider->user($user->scimId);
        self::assertSame(['Ada King', 'King', false, 'ada.lovelace@example.com'], [$stored['displayName'], $stored['name']['familyName'] ?? null, $stored['active'], $stored['emails'][0]['value'] ?? null]);
    }

    #[Test]
    #[TestDox('Delete: PATCH replace of "active" with a path; a 404 counts as success')]
    public function deactivatesUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($user);
        $scimId = (string) $user->scimId;
        $this->serviceProvider->forgetRequests();

        $this->provisioner->deleteUser($scimId);
        $this->provisioner->deleteUser('vanished');

        $deactivation = ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]];
        self::assertRequests([['PATCH /Users/'.$scimId, $deactivation], ['PATCH /Users/vanished', $deactivation]]);
        self::assertFalse($this->serviceProvider->user($scimId)['active']);
    }

    #[Test]
    #[TestDox('A linked user that the service provider no longer has (404) is looked up and created again')]
    public function recreatesUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($user);
        $this->serviceProvider->users->delete((string) $user->scimId);
        $goneId = $user->scimId;
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncUser($user);

        self::assertSame(['PATCH /Users/'.$goneId, 'GET /Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22', 'POST /Users'], array_column($this->serviceProvider->requests, 'line'));
        self::assertNotSame($goneId, $user->scimId);
        self::assertSame('ada.lovelace@example.com', $this->serviceProvider->user($user->scimId)['userName']);
    }

    #[Test]
    #[TestDox('A userName match is adopted only when its externalId is the Keycloak id')]
    public function adoptsOwnUserOnly(): void
    {
        $ada = self::newUser('Ada', 'Lovelace');
        $grace = self::newUser('Grace', 'Hopper');
        $own = $this->serviceProvider->users->create(['schemas' => [self::USER], 'userName' => 'ada.lovelace@example.com', 'externalId' => $this->keycloakId($ada)]);
        $this->serviceProvider->users->create(['schemas' => [self::USER], 'userName' => 'grace.hopper@example.com', 'externalId' => 'hr-system-7']);

        $this->provisioner->syncUser($ada);

        try {
            $this->provisioner->syncUser($grace);
            self::fail('ProvisioningFailed expected.');
        } catch (ProvisioningFailed $provisioningFailed) {
            self::assertSame(\sprintf('The service provider resource that matches "grace.hopper@example.com" has another externalId than %s. The IdP leaves it untouched.', $this->keycloakId($grace)), $provisioningFailed->getMessage());
        }

        self::assertSame($own['id'], $ada->scimId);
        self::assertNull($grace->scimId);
        self::assertSame([
            'GET /ServiceProviderConfig',
            'GET /Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22',
            'PATCH /Users/'.$ada->scimId,
            'GET /Users?filter=userName%20eq%20%22grace.hopper%40example.com%22',
        ], array_column($this->serviceProvider->requests, 'line'));
        self::assertSame('Ada Lovelace', $this->serviceProvider->user($ada->scimId)['displayName']);
    }

    #[Test]
    #[TestDox('Without PATCH support: PUT of the full user, deactivation by GET and PUT, groups by PUT with every member')]
    public function putsWithoutPatchSupport(): void
    {
        $this->serviceProvider->patchSupported = false;
        $user = self::newUser('Ada', 'Lovelace');
        $user->mobilePhone = '555';

        $this->provisioner->syncUser($user);
        $group = self::newGroup('Engineering', $user);
        $this->provisioner->syncGroup($group);
        $this->serviceProvider->forgetRequests();
        $user->familyName = 'King';
        $group->displayName = 'Research';

        $this->provisioner->syncUser($user);
        $this->provisioner->syncGroup($group);
        $this->provisioner->deleteUser((string) $user->scimId);

        $read = (array) $this->serviceProvider->requests[2]['response'];
        self::assertRequests([
            ['PUT /Users/'.$user->scimId, [
                'schemas' => [self::USER, self::ENTERPRISE],
                'id' => $user->scimId,
                'externalId' => $this->keycloakId($user),
                'userName' => 'ada.lovelace@example.com',
                'name' => ['givenName' => 'Ada', 'familyName' => 'King'],
                'displayName' => 'Ada King',
                'emails' => [['value' => 'ada.lovelace@example.com', 'type' => 'work', 'primary' => true]],
                'active' => true,
                self::ENTERPRISE => ['department' => 'Engineering'],
            ]],
            ['PUT /Groups/'.$group->scimId, ['schemas' => [self::GROUP], 'id' => $group->scimId, 'externalId' => $this->keycloakId($group), 'displayName' => 'Research', 'members' => [['value' => $user->scimId]]]],
            ['GET /Users/'.$user->scimId, null],
            ['PUT /Users/'.$user->scimId, [
                'schemas' => $read['schemas'],
                'id' => $user->scimId,
                'externalId' => $this->keycloakId($user),
                'userName' => 'ada.lovelace@example.com',
                'name' => ['givenName' => 'Ada', 'familyName' => 'King'],
                'displayName' => 'Ada King',
                'emails' => [['value' => 'ada.lovelace@example.com', 'type' => 'work', 'primary' => true]],
                'active' => false,
                self::ENTERPRISE => ['department' => 'Engineering'],
            ]],
        ]);
        self::assertFalse($this->serviceProvider->user($user->scimId)['active']);
        self::assertSame('Research', $this->serviceProvider->group($group->scimId)['displayName']);
    }

    #[Test]
    #[TestDox('A failed /ServiceProviderConfig read assumes PATCH support')]
    public function assumesPatchSupport(): void
    {
        self::useToken('wrong-token');
        $user = self::newUser('Ada', 'Lovelace');

        try {
            $this->provisioner->syncUser($user);
            self::fail('ProvisioningFailed expected.');
        } catch (ProvisioningFailed) {
        }

        $this->serviceProvider->patchSupported = false;
        self::useToken(FakeServiceProvider::TOKEN);
        $this->provisioner->syncUser($user);
        $this->provisioner->syncUser($user);

        self::assertSame(
            ['GET /ServiceProviderConfig 401', 'GET /Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22 401', 'GET /Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22 200', 'POST /Users 201', 'PATCH /Users/'.$user->scimId.' 204'],
            array_map(static fn (array $request): string => $request['line'].' '.$request['status'], $this->serviceProvider->requests),
        );
    }

    #[Test]
    #[TestDox('Group create: displayName filter, then POST with the Keycloak id and the members')]
    public function createsGroup(): void
    {
        $ada = self::syncedUser('Ada', 'Lovelace');
        $grace = self::syncedUser('Grace', 'Hopper');
        $group = self::newGroup('Engineering', $ada, $grace);
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncGroup($group);

        self::assertRequests([
            ['GET /Groups?filter=displayName%20eq%20%22Engineering%22', null],
            ['POST /Groups', ['schemas' => [self::GROUP], 'externalId' => $this->keycloakId($group), 'displayName' => 'Engineering', 'members' => [['value' => $ada->scimId], ['value' => $grace->scimId]]]],
        ]);
        self::assertSame([['value' => $ada->scimId], ['value' => $grace->scimId]], $this->serviceProvider->group($group->scimId)['members']);
    }

    #[Test]
    #[TestDox('Group create: at most 100 members in the POST, the rest by PATCH')]
    public function chunksMembers(): void
    {
        $members = [];

        foreach (range(1, 101) as $number) {
            $member = self::newUser('User', 'Number'.$number);
            $member->scimId = $this->serviceProvider->users->create(['schemas' => [self::USER], 'userName' => $member->userName])['id'] ?? null;
            $members[] = $member;
        }

        $group = self::newGroup('Everyone', ...$members);
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncGroup($group);

        self::assertSame(['GET /ServiceProviderConfig', 'GET /Groups?filter=displayName%20eq%20%22Everyone%22', 'POST /Groups', 'PATCH /Groups/'.$group->scimId], array_column($this->serviceProvider->requests, 'line'));
        self::assertCount(100, $this->serviceProvider->requests[2]['body']['members'] ?? []);
        self::assertSame([['op' => 'add', 'path' => 'members', 'value' => [['value' => $members[100]->scimId]]]], $this->serviceProvider->requests[3]['body']['Operations'] ?? null);
        self::assertCount(101, $this->serviceProvider->group($group->scimId)['members']);
    }

    #[Test]
    #[TestDox('Group change: displayName PATCH on each sync, then "members" add and remove with value arrays')]
    public function changesGroupMembers(): void
    {
        $kept = self::syncedUser('Ada', 'Lovelace');
        $removed = self::syncedUser('Grace', 'Hopper');
        $added = self::syncedUser('Linus', 'Torvalds');
        $group = self::newGroup('Engineering', $kept, $removed);
        $this->provisioner->syncGroup($group);
        $this->serviceProvider->forgetRequests();
        $group->displayName = 'Research';
        $group->members->removeElement($removed);
        $group->members->add($added);

        $this->provisioner->syncGroup($group);
        $this->provisioner->syncGroup($group);

        $rename = ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'replace', 'path' => 'displayName', 'value' => 'Research']]];
        self::assertRequests([
            ['PATCH /Groups/'.$group->scimId, $rename],
            ['PATCH /Groups/'.$group->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => $added->scimId]]]]]],
            ['PATCH /Groups/'.$group->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'remove', 'path' => 'members', 'value' => [['value' => $removed->scimId]]]]]],
            ['PATCH /Groups/'.$group->scimId, $rename],
        ]);
        self::assertSame('Research', $this->serviceProvider->group($group->scimId)['displayName']);
        self::assertSame([['value' => $kept->scimId], ['value' => $added->scimId]], $this->serviceProvider->group($group->scimId)['members']);
    }

    #[Test]
    #[TestDox('A displayName match with the Keycloak id is adopted, and its members are made equal')]
    public function adoptsOwnGroup(): void
    {
        $ada = self::syncedUser('Ada', 'Lovelace');
        $stray = self::syncedUser('Grace', 'Hopper');
        $group = self::newGroup('Engineering', $ada);
        $own = $this->serviceProvider->groups->create(['schemas' => [self::GROUP], 'displayName' => 'Engineering', 'externalId' => $this->keycloakId($group), 'members' => [['value' => $stray->scimId]]]);
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncGroup($group);

        self::assertSame($own['id'], $group->scimId);
        self::assertSame(['GET /Groups?filter=displayName%20eq%20%22Engineering%22', 'PATCH /Groups/'.$group->scimId, 'PATCH /Groups/'.$group->scimId], array_column($this->serviceProvider->requests, 'line'));
        self::assertSame([['value' => $ada->scimId]], $this->serviceProvider->group($group->scimId)['members']);
    }

    #[Test]
    #[TestDox('Without PATCH support, an adopted group gets a PUT with every member')]
    public function putsAdoptedGroup(): void
    {
        $this->serviceProvider->patchSupported = false;
        $ada = self::syncedUser('Ada', 'Lovelace');
        $group = self::newGroup('Engineering', $ada);
        $own = $this->serviceProvider->groups->create(['schemas' => [self::GROUP], 'displayName' => 'Engineering', 'externalId' => $this->keycloakId($group)]);
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncGroup($group);

        self::assertSame($own['id'], $group->scimId);
        self::assertSame(['GET /Groups?filter=displayName%20eq%20%22Engineering%22', 'PUT /Groups/'.$group->scimId], array_column($this->serviceProvider->requests, 'line'));
        self::assertSame([['value' => $ada->scimId]], $this->serviceProvider->group($group->scimId)['members']);
    }

    #[Test]
    #[TestDox('A linked group that the service provider no longer has (404) is created again')]
    public function recreatesGroup(): void
    {
        $ada = self::syncedUser('Ada', 'Lovelace');
        $group = self::newGroup('Engineering', $ada);
        $this->provisioner->syncGroup($group);
        $this->serviceProvider->groups->delete((string) $group->scimId);
        $goneId = $group->scimId;
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncGroup($group);

        self::assertSame(['PATCH /Groups/'.$goneId, 'GET /Groups?filter=displayName%20eq%20%22Engineering%22', 'POST /Groups'], array_column($this->serviceProvider->requests, 'line'));
        self::assertSame([['value' => $ada->scimId]], $this->serviceProvider->group($group->scimId)['members']);
    }

    private function syncedUser(string $givenName, string $familyName): DirectoryUser
    {
        $user = self::newUser($givenName, $familyName);
        $this->provisioner->syncUser($user);

        return $user;
    }

    private function keycloakId(DirectoryUser|DirectoryGroup $entry): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', $entry->id);
    }
}
