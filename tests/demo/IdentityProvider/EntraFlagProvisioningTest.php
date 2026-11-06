<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;

#[TestDox('Entra with the aadOptscim062020 flag')]
final class EntraFlagProvisioningTest extends KernelTestCase
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
        self::useSettings(Dialect::EntraFlag);
        $this->provisioner = self::service(Provisioner::class);
        $this->serviceProvider = self::fakeServiceProvider();
    }

    #[Test]
    #[TestDox('Create: lowercase "add" of the manager')]
    public function createsUser(): void
    {
        $manager = self::newUser('Grace', 'Hopper');
        $user = self::newUser('Ada', 'Lovelace', $manager);
        $this->provisioner->syncUser($manager);
        $this->serviceProvider->forgetRequests();

        $this->provisioner->syncUser($user);

        self::assertSame(['GET', 'GET', 'POST', 'PATCH'], array_map(static fn (array $request): string => strstr($request['line'], ' ', true) ?: '', $this->serviceProvider->requests));
        self::assertSame(['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'add', 'path' => self::ENTERPRISE.':manager', 'value' => $manager->scimId]]], $this->serviceProvider->requests[3]['body']);
    }

    #[Test]
    #[TestDox('Update: filtered paths and new values in their own operations, replaced values in one operation without path')]
    public function updatesUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $user->employeeNumber = 'E001';
        $user->department = null;

        $this->provisioner->syncUser($user);
        $this->serviceProvider->forgetRequests();
        $user->givenName = 'Augusta';
        $user->familyName = 'King';
        $user->employeeNumber = 'E002';
        $user->workEmail = 'augusta.king@example.com';
        $user->mobilePhone = '555';
        $user->department = 'Research';

        $this->provisioner->syncUser($user);

        self::assertRequests([
            ['GET /Users/'.$user->scimId, null],
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [
                ['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'augusta.king@example.com'],
                ['op' => 'replace', 'path' => 'phoneNumbers[type eq "mobile"].value', 'value' => '555'],
                ['op' => 'add', 'path' => self::ENTERPRISE.':department', 'value' => 'Research'],
                ['op' => 'replace', 'value' => [
                    'name.givenName' => 'Augusta',
                    'name.familyName' => 'King',
                    self::ENTERPRISE.':employeeNumber' => 'E002',
                ]],
            ]]],
        ]);
        $stored = $this->serviceProvider->user($user->scimId);
        self::assertSame(['Augusta', 'King'], [$stored['name']['givenName'] ?? null, $stored['name']['familyName'] ?? null]);
        self::assertSame(['department' => 'Research', 'employeeNumber' => 'E002'], [
            'department' => $stored[self::ENTERPRISE]['department'] ?? null,
            'employeeNumber' => $stored[self::ENTERPRISE]['employeeNumber'] ?? null,
        ]);
        self::assertSame('555', $stored['phoneNumbers'][0]['value'] ?? null);
    }

    #[Test]
    #[TestDox('Disable: lowercase "replace" with a JSON boolean')]
    public function disablesUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $this->provisioner->syncUser($user);
        $this->serviceProvider->forgetRequests();
        $user->active = false;

        $this->provisioner->syncUser($user);

        self::assertRequests([
            ['GET /Users/'.$user->scimId, null],
            ['PATCH /Users/'.$user->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]],
        ]);
        self::assertFalse($this->serviceProvider->user($user->scimId)['active']);
    }

    #[Test]
    #[TestDox('Group member removal: "remove" with a members[value eq] filter and no value')]
    public function removesGroupMember(): void
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
        $group->members->removeElement($removed);
        $group->members->add($added);

        $this->provisioner->syncGroup($group);

        self::assertRequests([
            ['GET /Groups/'.$group->scimId.'?excludedAttributes=members', null],
            ['PATCH /Groups/'.$group->scimId, ['schemas' => [self::PATCH_OP], 'Operations' => [
                ['op' => 'add', 'path' => 'members', 'value' => [['$ref' => null, 'value' => $added->scimId]]],
                ['op' => 'remove', 'path' => \sprintf('members[value eq "%s"]', $removed->scimId)],
            ]]],
        ]);
        self::assertSame([['value' => $kept->scimId], ['value' => $added->scimId]], $this->serviceProvider->group($group->scimId)['members']);
    }
}
