<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\ProvisioningFailed;

#[TestDox('Entra without the aadOptscim062020 flag')]
final class EntraProvisioningTest extends KernelTestCase
{
    use IdentityProviderKernel;
    private const string PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    private Provisioner $provisioner;

    private FakeServiceProvider $serviceProvider;

    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();
        $this->provisioner = self::service(Provisioner::class);
        $this->serviceProvider = self::fakeServiceProvider();
    }

    #[Test]
    #[TestDox('Create: filter by userName, then externalId, POST, then PATCH the manager as a bare string')]
    public function createsUser(): void
    {
        $manager = self::newUser('Grace', 'Hopper');
        $user = self::newUser('Ada', 'Lovelace', $manager);
        $user->mobilePhone = '+61 400 000 000';
        $user->employeeNumber = 'E001';

        $this->provisioner->syncUser($manager);
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncUser($user);

        self::assertRequests([
            ['GET /Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22', null],
            ['GET /Users?filter=externalId%20eq%20%22ada.lovelace%22', null],
            ['POST /Users', [
                'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User', self::ENTERPRISE],
                'externalId' => 'ada.lovelace',
                'userName' => 'ada.lovelace@example.com',
                'active' => true,
                'displayName' => 'Ada Lovelace',
                'emails' => [['primary' => true, 'type' => 'work', 'value' => 'ada.lovelace@example.com']],
                'phoneNumbers' => [['type' => 'mobile', 'value' => '+61 400 000 000']],
                'meta' => ['resourceType' => 'User'],
                'name' => ['formatted' => 'Ada Lovelace', 'familyName' => 'Lovelace', 'givenName' => 'Ada'],
                'roles' => [],
                self::ENTERPRISE => ['department' => 'Engineering', 'employeeNumber' => 'E001'],
            ]],
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [
                ['op' => 'Add', 'path' => self::ENTERPRISE.':manager', 'value' => $manager->scimId],
            ]]],
        ]);
        self::assertSame(['accept' => 'application/scim+json', 'authorization' => 'Bearer demo-token', 'content-type' => 'application/scim+json'], $this->serviceProvider->requests[2]['headers']);
        self::assertSame(['accept' => 'application/scim+json', 'authorization' => 'Bearer demo-token'], $this->serviceProvider->requests[0]['headers']);
        self::assertSame($manager->scimId, $this->serviceProvider->user($user->scimId)[self::ENTERPRISE]['manager']['value'] ?? null);
    }

    #[Test]
    #[TestDox('Update: GET, then one PATCH operation per changed attribute, "Add" for a new value')]
    public function updatesUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($user);
        $this->serviceProvider->forgetRequests();
        $user->givenName = 'Augusta';
        $user->mobilePhone = '555';
        $user->department = 'Research';

        $this->provisioner->syncUser($user);

        self::assertRequests([
            ['GET /Users/'.$user->scimId, null],
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [
                ['op' => 'Replace', 'path' => 'name.givenName', 'value' => 'Augusta'],
                ['op' => 'Add', 'path' => 'phoneNumbers[type eq "mobile"].value', 'value' => '555'],
                ['op' => 'Replace', 'path' => self::ENTERPRISE.':department', 'value' => 'Research'],
            ]]],
        ]);
        $stored = $this->serviceProvider->user($user->scimId);
        self::assertSame('Augusta', $stored['name']['givenName'] ?? null);
        self::assertSame([['type' => 'mobile', 'value' => '555']], $stored['phoneNumbers']);
        self::assertSame('Research', $stored[self::ENTERPRISE]['department'] ?? null);
    }

    #[Test]
    #[TestDox('Disable and enable: PATCH active with the strings "False" and "True"')]
    public function disablesAndEnablesUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($user);
        $this->serviceProvider->forgetRequests();

        $user->active = false;
        $this->provisioner->syncUser($user);
        $disabled = $this->serviceProvider->user($user->scimId)['active'];
        $user->active = true;
        $this->provisioner->syncUser($user);

        self::assertRequests([
            ['GET /Users/'.$user->scimId, null],
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']]]],
            ['GET /Users/'.$user->scimId, null],
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'True']]]],
        ]);
        self::assertFalse($disabled);
        self::assertTrue($this->serviceProvider->user($user->scimId)['active']);
    }

    #[Test]
    #[TestDox('Manager: "Replace" when it changes, "Remove" when it is cleared, nothing while it is not provisioned')]
    public function changesAndClearsManager(): void
    {
        $first = self::newUser('Grace', 'Hopper');
        $second = self::newUser('Linus', 'Torvalds');
        $user = self::newUser('Ada', 'Lovelace', $first);
        $this->provisioner->syncUser($first);
        $this->provisioner->syncUser($second);
        $this->provisioner->syncUser($user);

        $unprovisioned = self::newUser('Mary', 'Jackson');
        $this->serviceProvider->forgetRequests();

        $user->manager = $second;
        $this->provisioner->syncUser($user);
        $user->manager = $unprovisioned;
        $this->provisioner->syncUser($user);
        $user->manager = null;
        $this->provisioner->syncUser($user);

        self::assertRequests([
            ['GET /Users/'.$user->scimId, null],
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'Replace', 'path' => self::ENTERPRISE.':manager', 'value' => $second->scimId]]]],
            ['GET /Users/'.$user->scimId, null],
            ['GET /Users/'.$user->scimId, null],
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'Remove', 'path' => self::ENTERPRISE.':manager']]]],
        ]);
        self::assertArrayNotHasKey('manager', (array) ($this->serviceProvider->user($user->scimId)[self::ENTERPRISE] ?? []));
    }

    #[Test]
    #[TestDox('Delete: DELETE')]
    public function deletesUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($user);
        $scimId = (string) $user->scimId;
        $this->serviceProvider->forgetRequests();

        $this->provisioner->deleteUser($scimId);

        self::assertRequests([['DELETE /Users/'.$scimId, null]]);
        self::assertNull($this->serviceProvider->users->get($scimId));
    }

    #[Test]
    #[TestDox('A user that the userName filter finds is adopted and PATCHed, not created')]
    public function adoptsMatchingUser(): void
    {
        $existing = $this->serviceProvider->users->create(['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'userName' => 'ada.lovelace@example.com', 'active' => true]);
        $user = self::newUser('Ada', 'Lovelace');
        $user->department = null;

        $this->provisioner->syncUser($user);

        self::assertSame($existing['id'], $user->scimId);
        self::assertRequests([
            ['GET /Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22', null],
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [
                ['op' => 'Add', 'path' => 'externalId', 'value' => 'ada.lovelace'],
                ['op' => 'Add', 'path' => 'displayName', 'value' => 'Ada Lovelace'],
                ['op' => 'Add', 'path' => 'name.givenName', 'value' => 'Ada'],
                ['op' => 'Add', 'path' => 'name.familyName', 'value' => 'Lovelace'],
                ['op' => 'Add', 'path' => 'emails[type eq "work"].value', 'value' => 'ada.lovelace@example.com'],
            ]]],
        ]);
        self::assertSame([['type' => 'work', 'value' => 'ada.lovelace@example.com']], $this->serviceProvider->user($user->scimId)['emails']);
    }

    #[Test]
    #[TestDox('A full sync fails a user whose externalId matches a resource of another user, and sends no change')]
    public function refusesMatchOfOtherUser(): void
    {
        $first = self::newUser('Ada', 'Lovelace');
        $first->userName = 'ada@example.com';
        self::save($first);
        $second = self::newUser('Ada', 'Byron');
        $second->userName = 'ada@example.org';
        self::save($second);

        $failures = $this->provisioner->syncAll();

        self::assertSame([\sprintf('The service provider resource %s that matches "ada@example.org" is already linked to "ada@example.com".', $first->scimId)], $failures);
        self::assertNull($second->scimId);
        self::assertSame([
            'GET /Users?filter=userName%20eq%20%22ada%40example.com%22',
            'GET /Users?filter=externalId%20eq%20%22ada%22',
            'POST /Users',
            'GET /Users?filter=userName%20eq%20%22ada%40example.org%22',
            'GET /Users?filter=externalId%20eq%20%22ada%22',
        ], array_column($this->serviceProvider->requests, 'line'));
        self::assertSame('ada@example.com', $this->serviceProvider->user($first->scimId)['userName']);
    }

    #[Test]
    #[TestDox('A group whose name matches a resource of another group fails and sends no change')]
    public function refusesMatchOfOtherGroup(): void
    {
        $first = self::newGroup('Engineering');
        $this->provisioner->syncGroup($first);
        $second = self::newGroup('engineering');
        $this->serviceProvider->forgetRequests();

        try {
            $this->provisioner->syncGroup($second);
            self::fail('ProvisioningFailed expected.');
        } catch (ProvisioningFailed $provisioningFailed) {
            self::assertSame(\sprintf('The service provider resource %s that matches "engineering" is already linked to "Engineering".', $first->scimId), $provisioningFailed->getMessage());
        }

        self::assertNull($second->scimId);
        self::assertSame(['GET /Groups?excludedAttributes=members&filter=displayName%20eq%20%22engineering%22'], array_column($this->serviceProvider->requests, 'line'));
        self::assertSame('Engineering', $this->serviceProvider->group($first->scimId)['displayName']);
    }

    #[Test]
    #[TestDox('A linked user that the service provider no longer has is created again')]
    public function recreatesVanishedUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $user->scimId = 'vanished';

        $this->provisioner->syncUser($user);

        self::assertSame(['GET /Users/vanished', 'GET /Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22', 'GET /Users?filter=externalId%20eq%20%22ada.lovelace%22', 'POST /Users'], array_column($this->serviceProvider->requests, 'line'));
        self::assertNotSame('vanished', $user->scimId);
        self::assertSame('ada.lovelace@example.com', $this->serviceProvider->user($user->scimId)['userName']);
    }

    #[Test]
    #[TestDox('Group create: filter without members, POST without members, then PATCH the provisioned members')]
    public function createsGroup(): void
    {
        $member = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($member);
        $group = self::newGroup('Engineering', $member, self::newUser('Mary', 'Jackson'));
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncGroup($group);

        self::assertRequests([
            ['GET /Groups?excludedAttributes=members&filter=displayName%20eq%20%22Engineering%22', null],
            ['POST /Groups', [
                'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group', 'http://schemas.microsoft.com/2006/11/ResourceManagement/ADSCIM/2.0/Group'],
                'externalId' => (string) $group->id,
                'displayName' => 'Engineering',
                'meta' => ['resourceType' => 'Group'],
            ]],
            ['PATCH /Groups/'.$group->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [
                ['op' => 'Add', 'path' => 'members', 'value' => [['$ref' => null, 'value' => $member->scimId]]],
            ]]],
        ]);
        self::assertSame([(string) $member->scimId], $group->provisionedMemberIds);
        self::assertSame([['value' => $member->scimId]], $this->serviceProvider->group($group->scimId)['members']);
    }

    #[Test]
    #[TestDox('Group update: GET without members, PATCH the name, PATCH "Remove" with a value array')]
    public function updatesGroup(): void
    {
        $kept = self::newUser('Ada', 'Lovelace');
        $removed = self::newUser('Grace', 'Hopper');
        $this->provisioner->syncUser($kept);
        $this->provisioner->syncUser($removed);

        $group = self::newGroup('Engineering', $kept, $removed);
        $this->provisioner->syncGroup($group);
        $this->serviceProvider->forgetRequests();
        $group->displayName = 'Research';
        $group->members->removeElement($removed);

        $this->provisioner->syncGroup($group);

        self::assertRequests([
            ['GET /Groups/'.$group->scimId.'?excludedAttributes=members', null],
            ['PATCH /Groups/'.$group->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'Replace', 'path' => 'displayName', 'value' => 'Research']]]],
            ['PATCH /Groups/'.$group->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [
                ['op' => 'Remove', 'path' => 'members', 'value' => [['$ref' => null, 'value' => $removed->scimId]]],
            ]]],
        ]);
        self::assertSame(['displayName' => 'Research', 'members' => [['value' => $kept->scimId]]], array_intersect_key($this->serviceProvider->group($group->scimId), ['displayName' => 1, 'members' => 1]));
    }

    #[Test]
    #[TestDox('Group delete: DELETE')]
    public function deletesGroup(): void
    {
        $group = self::newGroup('Engineering');
        $this->provisioner->syncGroup($group);
        $scimId = (string) $group->scimId;
        $this->serviceProvider->forgetRequests();

        $this->provisioner->deleteGroup($scimId);

        self::assertRequests([['DELETE /Groups/'.$scimId, null]]);
        self::assertNull($this->serviceProvider->groups->get($scimId));
    }
}
