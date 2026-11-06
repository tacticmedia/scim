<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Tests\Conformance\Fixtures\IdpClient;

/**
 * The Keycloak plugin shebang-labs/scim-outbound-for-keycloak, var/research/idp-okta-others.md section 6 and the
 * Keycloak quirks of section 7. The report names no request headers; these are the plugin's Bearer mode.
 */
final class KeycloakTest extends KernelTestCase
{
    use IdpClient;
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const array HEADERS = ['Authorization' => 'Bearer secret-token'];
    private const array BODY_HEADERS = ['Content-Type' => 'application/scim+json'];
    private const string KEYCLOAK_ID = '6f1f5c8e-0e43-4d4b-9a4b-2f9c1d7e8a10';

    #[Test]
    #[TestDox('Probe: /ServiceProviderConfig advertises PATCH, filter, maxResults and the authentication schemes (quirk 33)')]
    public function advertisesPatch(): void
    {
        $this->seed();

        $config = self::json($this->request('GET', '/ServiceProviderConfig'));

        self::assertSame([true, true, 100, []], [$config['patch']['supported'] ?? null, $config['filter']['supported'] ?? null, $config['filter']['maxResults'] ?? null, $config['authenticationSchemes'] ?? null]);
    }

    #[Test]
    #[TestDox('Lookup before create: escaped and %20-encoded userName and displayName filters find the resources (quirks 7, 10)')]
    public function looksUpByEscapedValues(): void
    {
        $this->seed([self::storedUser('u1', 'o"brien\\x', ['externalId' => self::KEYCLOAK_ID])], [self::storedGroup('g1', 'R&D "core"')]);

        $user = self::resources($this->request('GET', '/Users?filter='.rawurlencode('userName eq "o\\"brien\\\\x"')));

        self::assertSame([['u1', self::KEYCLOAK_ID]], array_map(static fn (array $resource): array => [$resource['id'] ?? null, $resource['externalId'] ?? null], $user));
        self::assertSame(['g1'], self::ids($this->request('GET', '/Groups?filter='.rawurlencode('displayName eq "R&D \\"core\\""'))));
    }

    #[Test]
    #[TestDox('Create: a duplicate userName answers 409 uniqueness, which the plugin turns into a lookup (quirk 20)')]
    public function rejectsDuplicateCreate(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen', ['externalId' => self::KEYCLOAK_ID])]);

        $response = $this->request('POST', '/Users', ['schemas' => [self::USER], 'externalId' => self::KEYCLOAK_ID, 'userName' => 'bjensen', 'active' => true]);

        self::assertScimError($response, 409, 'uniqueness', 'The value of attribute "userName" is already in use.');
        self::assertSame(['u1'], self::ids($this->request('GET', '/Users?filter=userName%20eq%20%22bjensen%22')));
    }

    #[Test]
    #[TestDox('User update: path-ful replace of name merges (RFC 7644 3.5.2.3), of emails replaces all, of the extension URN sets its attributes (quirks 40, 41)')]
    public function updatesUser(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen', [
            'name' => ['givenName' => 'Barbara', 'middleName' => 'Jane', 'familyName' => 'Jensen'],
            'emails' => [['value' => 'bjensen@example.com', 'type' => 'work', 'primary' => true], ['value' => 'babs@jensen.org', 'type' => 'home']],
        ])]);

        self::assertNoContent($this->request('PATCH', '/Users/u1', self::patchOp(
            ['op' => 'replace', 'path' => 'userName', 'value' => 'babs'],
            ['op' => 'replace', 'path' => 'externalId', 'value' => self::KEYCLOAK_ID],
            ['op' => 'replace', 'path' => 'active', 'value' => true],
            ['op' => 'replace', 'path' => 'displayName', 'value' => 'Babs Jensen'],
            ['op' => 'replace', 'path' => 'name', 'value' => ['givenName' => 'Babs', 'familyName' => 'Jensen']],
            ['op' => 'replace', 'path' => 'emails', 'value' => [['value' => 'babs@example.com', 'type' => 'work', 'primary' => true]]],
            ['op' => 'replace', 'path' => 'title', 'value' => 'Tour Guide'],
            ['op' => 'replace', 'path' => 'preferredLanguage', 'value' => 'en-US'],
            ['op' => 'replace', 'path' => 'locale', 'value' => 'en-US'],
            ['op' => 'replace', 'path' => self::ENTERPRISE, 'value' => ['department' => 'Tour Operations', 'employeeNumber' => '701984']],
        )));

        $user = self::json($this->request('GET', '/Users/u1'));
        self::assertSame(['givenName' => 'Babs', 'middleName' => 'Jane', 'familyName' => 'Jensen'], $user['name'] ?? null);
        self::assertSame([['value' => 'babs@example.com', 'type' => 'work', 'primary' => true]], $user['emails'] ?? null);
        self::assertSame(['employeeNumber' => '701984', 'department' => 'Tour Operations'], $user[self::ENTERPRISE] ?? null);
        self::assertSame(['babs', self::KEYCLOAK_ID, 'Babs Jensen', 'Tour Guide', 'en-US', 'en-US', true], [$user['userName'] ?? null, $user['externalId'] ?? null, $user['displayName'] ?? null, $user['title'] ?? null, $user['preferredLanguage'] ?? null, $user['locale'] ?? null, $user['active'] ?? null]);
    }

    #[Test]
    #[TestDox('Without PATCH: PUT with the full user and id answers 200 and replaces the user (quirks 3, 4)')]
    public function replacesUser(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen', ['title' => 'Tour Guide'])]);

        $replaced = $this->request('PUT', '/Users/u1', ['schemas' => [self::USER], 'id' => 'u1', 'externalId' => self::KEYCLOAK_ID, 'userName' => 'bjensen', 'active' => false]);

        self::assertSame(200, $replaced->getStatusCode());
        self::assertSame(['schemas', 'id', 'meta', 'externalId', 'userName', 'active'], array_keys(self::json($replaced)));
    }

    #[Test]
    #[TestDox('Deactivation: PATCH replace of active with false answers 204; DELETE answers 204, a second DELETE 404 (quirk 26)')]
    public function deprovisionsUser(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen', ['active' => true])]);

        self::assertNoContent($this->request('PATCH', '/Users/u1', self::patchOp(['op' => 'replace', 'path' => 'active', 'value' => false])));
        self::assertFalse(($this->users->get('u1') ?? [])['active'] ?? null);
        self::assertNoContent($this->request('DELETE', '/Users/u1'));
        self::assertScimError($this->request('DELETE', '/Users/u1'), 404, null, 'Resource u1 not found.');
        self::assertScimError($this->request('PATCH', '/Users/u1', self::patchOp(['op' => 'replace', 'path' => 'active', 'value' => false])), 404, null, 'Resource u1 not found.');
    }

    #[Test]
    #[TestDox('Membership: add in a chunk of 100, remove with a value array, fallback remove by value filter, rename (quirks 12, 13, 16)')]
    public function changesMembership(): void
    {
        $ids = array_map(static fn (int $i): string => 'u'.$i, range(1, 100));
        $this->seed(groups: [self::storedGroup('g1', 'Tour Guides', ['members' => [['value' => 'u1']]])]);

        self::assertNoContent($this->request('PATCH', '/Groups/g1', self::patchOp(['op' => 'add', 'path' => 'members', 'value' => array_map(static fn (string $id): array => ['value' => $id], $ids)])));
        self::assertNoContent($this->request('PATCH', '/Groups/g1', self::patchOp(['op' => 'remove', 'path' => 'members', 'value' => [['value' => 'u2'], ['value' => 'u3']]])));
        self::assertNoContent($this->request('PATCH', '/Groups/g1', self::patchOp(['op' => 'remove', 'path' => 'members[value eq "u4"]'])));
        self::assertNoContent($this->request('PATCH', '/Groups/g1', self::patchOp(['op' => 'replace', 'path' => 'displayName', 'value' => 'Tour Leaders'])));

        $group = $this->groups->get('g1') ?? [];
        self::assertSame('Tour Leaders', $group['displayName'] ?? null);
        self::assertSame(['u1', ...\array_slice($ids, 4)], array_column((array) ($group['members'] ?? []), 'value'));
    }

    #[Test]
    #[TestDox('Member discovery: the groups.value filter on /Users answers 200 with the users whose stored groups match (quirk 38)')]
    public function filtersByGroups(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen', ['groups' => [['value' => 'g1']]]), self::storedUser('u2', 'jsmith')]);

        self::assertSame(['u1'], self::ids($this->request('GET', '/Users?filter=groups.value%20eq%20%22g1%22')));
    }

    #[Test]
    #[TestDox('Pagination: startIndex and count page; the RFC 9865 cursor parameter is ignored (quirks 9, 39)')]
    public function paginates(): void
    {
        $this->seed(array_map(static fn (int $i): array => self::storedUser('u'.$i, 'user'.$i), range(1, 5)));

        self::assertSame(['u3', 'u4'], self::ids($this->request('GET', '/Users?startIndex=3&count=2')));
        self::assertSame(['u1', 'u2'], self::ids($this->request('GET', '/Users?cursor&count=2')));
    }
}
