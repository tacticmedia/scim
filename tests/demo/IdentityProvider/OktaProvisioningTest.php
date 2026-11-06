<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\ProvisioningFailed;

#[TestDox('Okta')]
final class OktaProvisioningTest extends KernelTestCase
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
        self::useSettings(Dialect::Okta);
        $this->provisioner = self::service(Provisioner::class);
        $this->serviceProvider = self::fakeServiceProvider();
    }

    #[Test]
    #[TestDox('Create: paged userName filter, then POST with password, groups and the manager; token without "Bearer"')]
    public function createsUser(): void
    {
        $manager = self::newUser('Grace', 'Hopper');
        $user = self::newUser('Ada', 'Lovelace', $manager);
        $user->demoPlainTextPassword = 'secret';

        $this->provisioner->syncUser($manager);
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncUser($user);

        self::assertRequests([
            ['GET /Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22&startIndex=1&count=100', null],
            ['POST /Users', [
                'schemas' => [self::USER, self::ENTERPRISE],
                'userName' => 'ada.lovelace@example.com',
                'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace'],
                'emails' => [['primary' => true, 'value' => 'ada.lovelace@example.com', 'type' => 'work']],
                'displayName' => 'Ada Lovelace',
                'locale' => 'en-US',
                'externalId' => \sprintf('00u%017d', $user->id),
                'groups' => [],
                'password' => 'secret',
                'active' => true,
                self::ENTERPRISE => ['department' => 'Engineering', 'manager' => ['value' => $manager->scimId]],
            ]],
        ]);
        self::assertSame([
            'accept' => 'application/scim+json',
            'authorization' => 'demo-token',
            'content-type' => 'application/scim+json',
            'user-agent' => 'Okta SCIM Client 1.0.0',
        ], $this->serviceProvider->requests[1]['headers']);
        self::assertArrayNotHasKey('password', (array) $this->serviceProvider->requests[1]['response']);
        self::assertSame($manager->scimId, $this->serviceProvider->user($user->scimId)[self::ENTERPRISE]['manager']['value'] ?? null);
    }

    #[Test]
    #[TestDox('Profile update: GET, then PUT of the GET response with the changed attributes, id, meta and groups included')]
    public function replacesProfile(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($user);
        $this->serviceProvider->forgetRequests();
        $user->givenName = 'Augusta';
        $user->workEmail = null;
        $user->mobilePhone = '555';
        $user->department = null;

        $this->provisioner->syncUser($user);

        $read = (array) $this->serviceProvider->requests[0]['response'];
        unset($read['emails'], $read[self::ENTERPRISE]);
        self::assertRequests([
            ['GET /Users/'.$user->scimId, null],
            ['PUT /Users/'.$user->scimId, [
                ...$read,
                'name' => ['givenName' => 'Augusta', 'familyName' => 'Lovelace'],
                'phoneNumbers' => [['value' => '555', 'type' => 'mobile']],
            ]],
        ]);
        self::assertSame($user->scimId, $this->serviceProvider->requests[1]['body']['id'] ?? null);
        $stored = $this->serviceProvider->user($user->scimId);
        self::assertSame('Augusta', $stored['name']['givenName'] ?? null);
        self::assertArrayNotHasKey('emails', $stored);
        self::assertArrayNotHasKey(self::ENTERPRISE, $stored);
    }

    #[Test]
    #[TestDox('Deactivate and reactivate: path-less PATCH replace of active, no PUT')]
    public function deactivatesAndReactivatesUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($user);
        $this->serviceProvider->forgetRequests();

        $user->active = false;
        $this->provisioner->syncUser($user);
        $user->active = true;
        $this->provisioner->syncUser($user);

        self::assertRequests([
            ['GET /Users/'.$user->scimId, null],
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'replace', 'value' => ['active' => false]]]]],
            ['GET /Users/'.$user->scimId, null],
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'replace', 'value' => ['active' => true]]]]],
        ]);
        self::assertTrue($this->serviceProvider->user($user->scimId)['active']);
    }

    #[Test]
    #[TestDox('Delete: a deactivation, never a DELETE')]
    public function deactivatesInsteadOfDelete(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($user);
        $scimId = (string) $user->scimId;
        $this->serviceProvider->forgetRequests();

        $this->provisioner->deleteUser($scimId);

        self::assertRequests([
            ['PATCH /Users/'.$scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'replace', 'value' => ['active' => false]]]]],
        ]);
        self::assertFalse($this->serviceProvider->user($scimId)['active']);
    }

    #[Test]
    #[TestDox('A user whose userName matches a resource of another user fails and sends no change')]
    public function refusesMatchOfOtherUser(): void
    {
        $renamed = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($renamed);
        $renamed->userName = 'augusta.king@example.com';
        self::save($renamed);
        $user = self::newUser('Ada', 'Lovelace');
        $this->serviceProvider->forgetRequests();

        try {
            $this->provisioner->syncUser($user);
            self::fail('ProvisioningFailed expected.');
        } catch (ProvisioningFailed $provisioningFailed) {
            self::assertSame(\sprintf('The service provider resource %s that matches "ada.lovelace@example.com" is already linked to "augusta.king@example.com".', $renamed->scimId), $provisioningFailed->getMessage());
        }

        self::assertNull($user->scimId);
        self::assertSame(['GET /Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22&startIndex=1&count=100'], array_column($this->serviceProvider->requests, 'line'));
    }

    #[Test]
    #[TestDox('Manager: PUT with the new manager; an unprovisioned manager keeps the current one')]
    public function changesManager(): void
    {
        $first = self::newUser('Grace', 'Hopper');
        $second = self::newUser('Linus', 'Torvalds');
        $user = self::newUser('Ada', 'Lovelace', $first);
        $this->provisioner->syncUser($first);
        $this->provisioner->syncUser($second);
        $this->provisioner->syncUser($user);

        $user->manager = self::newUser('Mary', 'Jackson');
        $this->provisioner->syncUser($user);
        $unchanged = $this->serviceProvider->user($user->scimId)[self::ENTERPRISE]['manager']['value'] ?? null;
        $user->manager = $second;
        $this->provisioner->syncUser($user);

        self::assertSame($first->scimId, $unchanged);
        self::assertSame($second->scimId, $this->serviceProvider->user($user->scimId)[self::ENTERPRISE]['manager']['value'] ?? null);
        self::assertSame(['GET', 'PUT'], array_map(static fn (array $request): string => strstr($request['line'], ' ', true) ?: '', \array_slice($this->serviceProvider->requests, -2)));
    }

    #[Test]
    #[TestDox('An unchanged user gets a GET only')]
    public function skipsUnchangedUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($user);
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncUser($user);

        self::assertRequests([['GET /Users/'.$user->scimId, null]]);
    }

    #[Test]
    #[TestDox('A user that the userName filter finds is adopted and its profile PUT')]
    public function adoptsMatchingUser(): void
    {
        $existing = $this->serviceProvider->users->create(['schemas' => [self::USER], 'userName' => 'ada.lovelace@example.com', 'active' => true]);
        $user = self::newUser('Ada', 'Lovelace');

        $this->provisioner->syncUser($user);

        self::assertSame($existing['id'], $user->scimId);
        self::assertSame(['GET /Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22&startIndex=1&count=100', 'PUT /Users/'.$user->scimId], array_column($this->serviceProvider->requests, 'line'));
        self::assertSame('Ada Lovelace', $this->serviceProvider->user($user->scimId)['displayName']);
    }

    #[Test]
    #[TestDox('Group Push: paged filter, POST with empty members, rename PATCH with the id, then PATCH add members')]
    public function createsGroup(): void
    {
        $first = self::newUser('Ada', 'Lovelace');
        $second = self::newUser('Grace', 'Hopper');
        $this->provisioner->syncUser($first);
        $this->provisioner->syncUser($second);

        $group = self::newGroup('Engineering', $first, $second);
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncGroup($group);

        self::assertRequests([
            ['GET /Groups?filter=displayName%20eq%20%22Engineering%22&startIndex=1&count=100', null],
            ['POST /Groups', ['schemas' => [self::GROUP], 'displayName' => 'Engineering', 'members' => []]],
            ['PATCH /Groups/'.$group->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'replace', 'value' => ['id' => $group->scimId, 'displayName' => 'Engineering']]]]],
            ['PATCH /Groups/'.$group->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [
                ['op' => 'add', 'path' => 'members', 'value' => [
                    ['value' => $first->scimId, 'display' => 'ada.lovelace@example.com'],
                    ['value' => $second->scimId, 'display' => 'grace.hopper@example.com'],
                ]],
            ]]],
        ]);
        self::assertSame([['value' => $first->scimId], ['value' => $second->scimId]], $this->serviceProvider->group($group->scimId)['members']);
    }

    #[Test]
    #[TestDox('Group Push of an empty group: only the filter and the POST')]
    public function createsEmptyGroup(): void
    {
        $group = self::newGroup('Engineering');

        $this->provisioner->syncGroup($group);

        self::assertSame(['GET /Groups?filter=displayName%20eq%20%22Engineering%22&startIndex=1&count=100', 'POST /Groups'], array_column($this->serviceProvider->requests, 'line'));
    }

    #[Test]
    #[TestDox('Group membership change: rename PATCH, then remove members[value eq] and add in one PATCH')]
    public function changesGroupMembers(): void
    {
        $kept = self::newUser('Ada', 'Lovelace');
        $removed = self::newUser('Grace', 'Hopper');
        $added = self::newUser('Linus', 'Torvalds');
        $this->provisioner->syncUser($kept);
        $this->provisioner->syncUser($removed);
        $this->provisioner->syncUser($added);

        $group = self::newGroup('Engineering', $kept, $removed);
        $this->provisioner->syncGroup($group);
        $this->serviceProvider->forgetRequests();
        $group->displayName = 'Research';
        $group->members->removeElement($removed);
        $group->members->add($added);

        $this->provisioner->syncGroup($group);
        $this->provisioner->syncGroup($group);

        $rename = ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'replace', 'value' => ['id' => $group->scimId, 'displayName' => 'Research']]]];
        self::assertRequests([
            ['PATCH /Groups/'.$group->scimId, $rename],
            ['PATCH /Groups/'.$group->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [
                ['op' => 'remove', 'path' => \sprintf('members[value eq "%s"]', $removed->scimId)],
                ['op' => 'add', 'path' => 'members', 'value' => [['value' => $added->scimId, 'display' => 'linus.torvalds@example.com']]],
            ]]],
            ['PATCH /Groups/'.$group->scimId, $rename],
        ]);
        self::assertSame('Research', $this->serviceProvider->group($group->scimId)['displayName']);
        self::assertSame([['value' => $kept->scimId], ['value' => $added->scimId]], $this->serviceProvider->group($group->scimId)['members']);
    }

    #[Test]
    #[TestDox('A group that the displayName filter finds is adopted')]
    public function adoptsMatchingGroup(): void
    {
        $existing = $this->serviceProvider->groups->create(['schemas' => [self::GROUP], 'displayName' => 'Engineering']);
        $group = self::newGroup('Engineering');

        $this->provisioner->syncGroup($group);

        self::assertSame($existing['id'], $group->scimId);
        self::assertSame(['GET /Groups?filter=displayName%20eq%20%22Engineering%22&startIndex=1&count=100', 'PATCH /Groups/'.$group->scimId], array_column($this->serviceProvider->requests, 'line'));
    }
}
