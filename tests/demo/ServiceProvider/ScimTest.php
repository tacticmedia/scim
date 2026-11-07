<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\ServiceProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimDemo\ServiceProvider\Entity\User;

final class ScimTest extends WebTestCase
{
    use ServiceProviderKernel;
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const string ENTERPRISE_USER = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-06 10:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::createSchema();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    #[Test]
    #[TestDox('The firewall answers 401 without a token and with a wrong token, and accepts the bare token of Okta')]
    public function authenticates(): void
    {
        self::assertSame(401, $this->send('GET', '/Users', authorization: null)->getStatusCode());
        self::assertSame(401, $this->send('GET', '/Users', authorization: 'Bearer wrong-token')->getStatusCode());
        self::assertSame(0, $this->json($this->send('GET', '/Users', authorization: self::TOKEN))['totalResults'] ?? null);
    }

    #[Test]
    #[TestDox('A user is created, read, patched and deleted')]
    public function managesUser(): void
    {
        $managerId = $this->idOf($this->send('POST', '/Users', ['schemas' => [self::USER], 'userName' => 'grace.hopper@example.com', 'displayName' => 'Grace Hopper']));

        $created = $this->send('POST', '/Users', [
            'schemas' => [self::USER, self::ENTERPRISE_USER],
            'externalId' => 'ada',
            'userName' => 'ada.lovelace@example.com',
            'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace'],
            'displayName' => 'Ada Lovelace',
            'emails' => [['value' => 'ada.lovelace@example.com', 'type' => 'work', 'primary' => true]],
            'phoneNumbers' => [['value' => '+61 400 000 000', 'type' => 'mobile']],
            'title' => 'Analyst',
            'password' => 'Demo-Lovelace-1',
            self::ENTERPRISE_USER => ['employeeNumber' => 'E001', 'department' => 'Research', 'manager' => ['value' => $managerId]],
        ]);
        $id = $this->idOf($created);

        self::assertSame(201, $created->getStatusCode());
        self::assertSame($this->json($created), $this->json($this->send('GET', '/Users/'.$id)));
        self::assertSame([
            'schemas' => [self::USER, self::ENTERPRISE_USER],
            'id' => $id,
            'externalId' => 'ada',
            'meta' => ['resourceType' => 'User', 'created' => '2026-11-06T10:00:00Z', 'lastModified' => '2026-11-06T10:00:00Z', 'location' => 'http://localhost/scim/v2/Users/'.$id],
            'userName' => 'ada.lovelace@example.com',
            'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace', 'formatted' => 'Ada Lovelace'],
            'displayName' => 'Ada Lovelace',
            'title' => 'Analyst',
            'active' => true,
            'emails' => [['value' => 'ada.lovelace@example.com', 'type' => 'work', 'primary' => true]],
            'phoneNumbers' => [['value' => '+61 400 000 000', 'type' => 'mobile']],
            self::ENTERPRISE_USER => ['employeeNumber' => 'E001', 'department' => 'Research', 'manager' => ['value' => $managerId, 'displayName' => 'Grace Hopper', '$ref' => 'http://localhost/scim/v2/Users/'.$managerId]],
        ], $this->withoutVersion($this->json($created)));

        Clock::set(new MockClock('2026-11-06 11:00:00', 'UTC'));
        $patched = $this->send('PATCH', '/Users/'.$id, $this->patchOp(['op' => 'Replace', 'path' => 'displayName', 'value' => 'Ada King'], ['op' => 'Replace', 'path' => 'active', 'value' => 'False'], ['op' => 'Remove', 'path' => self::ENTERPRISE_USER.':manager']));

        self::assertSame(204, $patched->getStatusCode(), (string) $patched->getContent());
        $user = self::entityManager()->find(User::class, Uuid::fromString($id));
        self::assertInstanceOf(User::class, $user);
        self::assertSame(['Ada King', false, null, '2026-11-06T11:00:00+00:00'], [$user->getDisplayName(), $user->isActive(), $user->getManager(), $user->getUpdatedAt()->format(\DATE_ATOM)]);
        self::assertSame('2026-11-06T10:00:00+00:00', $user->getCreatedAt()->format(\DATE_ATOM));
        self::assertTrue($this->passwordHasher()->isPasswordValid($user, 'Demo-Lovelace-1'));

        self::assertSame(204, $this->send('DELETE', '/Users/'.$id)->getStatusCode());
        self::assertSame(404, $this->send('GET', '/Users/'.$id)->getStatusCode());
    }

    #[Test]
    #[TestDox('A group holds users and nested groups, rejects a duplicate name with 409 and loses removed members')]
    public function managesGroupWithMembers(): void
    {
        $userId = $this->idOf($this->send('POST', '/Users', ['schemas' => [self::USER], 'userName' => 'ada.lovelace@example.com']));
        $platformId = $this->idOf($this->send('POST', '/Groups', ['schemas' => [self::GROUP], 'displayName' => 'Platform']));

        $created = $this->send('POST', '/Groups', ['schemas' => [self::GROUP], 'displayName' => 'Engineering', 'members' => [['value' => $userId], ['value' => $platformId, 'type' => 'Group']]]);
        $groupId = $this->idOf($created);

        self::assertSame(201, $created->getStatusCode());
        self::assertEqualsCanonicalizing([
            ['value' => $userId, '$ref' => 'http://localhost/scim/v2/Users/'.$userId, 'display' => 'ada.lovelace@example.com', 'type' => 'User'],
            ['value' => $platformId, '$ref' => 'http://localhost/scim/v2/Groups/'.$platformId, 'display' => 'Platform', 'type' => 'Group'],
        ], $this->json($created)['members'] ?? null);
        self::assertSame([['value' => $groupId, 'display' => 'Engineering', '$ref' => 'http://localhost/scim/v2/Groups/'.$groupId]], $this->json($this->send('GET', '/Users/'.$userId))['groups'] ?? null);

        $duplicate = $this->send('POST', '/Groups', ['schemas' => [self::GROUP], 'displayName' => 'ENGINEERING']);
        self::assertSame([409, 'uniqueness'], [$duplicate->getStatusCode(), $this->json($duplicate)['scimType'] ?? null]);

        $patched = $this->send('PATCH', '/Groups/'.$groupId, $this->patchOp(['op' => 'remove', 'path' => \sprintf('members[value eq "%s"]', $userId)], ['op' => 'remove', 'path' => \sprintf('members[value eq "%s"]', $platformId)]));

        self::assertSame(204, $patched->getStatusCode(), (string) $patched->getContent());
        self::assertArrayNotHasKey('members', $this->json($this->send('GET', '/Groups/'.$groupId)));
        self::assertSame(204, $this->send('DELETE', '/Groups/'.$groupId)->getStatusCode());
    }

    #[Test]
    #[TestDox('A PUT of a group keeps the listed members and removes the other users and nested groups')]
    public function replacesGroupMembers(): void
    {
        $keptId = $this->idOf($this->send('POST', '/Users', ['schemas' => [self::USER], 'userName' => 'ada.lovelace@example.com']));
        $removedId = $this->idOf($this->send('POST', '/Users', ['schemas' => [self::USER], 'userName' => 'grace.hopper@example.com']));
        $platformId = $this->idOf($this->send('POST', '/Groups', ['schemas' => [self::GROUP], 'displayName' => 'Platform']));
        $groupId = $this->idOf($this->send('POST', '/Groups', ['schemas' => [self::GROUP], 'displayName' => 'Engineering', 'members' => [['value' => $keptId], ['value' => $removedId], ['value' => $platformId, 'type' => 'Group']]]));

        $replaced = $this->send('PUT', '/Groups/'.$groupId, ['schemas' => [self::GROUP], 'displayName' => 'Engineering', 'members' => [['value' => $keptId]]]);

        self::assertSame(200, $replaced->getStatusCode(), (string) $replaced->getContent());
        self::assertSame([$keptId], array_column($this->json($this->send('GET', '/Groups/'.$groupId))['members'] ?? [], 'value'));
    }

    #[Test]
    #[TestDox('The version column gives W/"1" on create and a new ETag after a member change, so the old ETag is 412')]
    public function versionsGroup(): void
    {
        $userId = $this->idOf($this->send('POST', '/Users', ['schemas' => [self::USER], 'userName' => 'ada.lovelace@example.com']));
        $created = $this->send('POST', '/Groups', ['schemas' => [self::GROUP], 'displayName' => 'Engineering']);
        $groupId = $this->idOf($created);

        $added = $this->send('PATCH', '/Groups/'.$groupId, $this->patchOp(['op' => 'add', 'path' => 'members', 'value' => [['value' => $userId]]]), ifMatch: 'W/"1"');
        $stale = $this->send('DELETE', '/Groups/'.$groupId, ifMatch: 'W/"1"');

        self::assertSame(['W/"1"', 204, 412], [$created->headers->get('ETag'), $added->getStatusCode(), $stale->getStatusCode()]);
        self::assertNotSame('W/"1"', $this->send('GET', '/Groups/'.$groupId)->headers->get('ETag'));
    }

    #[Test]
    #[TestDox('An invalid work email or a blank userName gives 400 invalidValue with the SCIM path, and nothing is stored')]
    public function validatesUser(): void
    {
        $badEmail = $this->send('POST', '/Users', ['schemas' => [self::USER], 'userName' => 'ada', 'emails' => [['value' => 'not an email', 'type' => 'work']]]);
        $blankUserName = $this->send('POST', '/Users', ['schemas' => [self::USER], 'userName' => ' ']);

        self::assertSame(
            [
                [400, 'invalidValue', 'Attribute "emails[type eq "work"].value": This value is not a valid email address.'],
                [400, 'invalidValue', 'Attribute "userName": This value should not be blank.'],
            ],
            array_map(fn (Response $response): array => [$response->getStatusCode(), $this->json($response)['scimType'] ?? null, $this->json($response)['detail'] ?? null], [$badEmail, $blankUserName]),
        );
        self::assertSame(0, $this->json($this->send('GET', '/Users'))['totalResults'] ?? null);
    }

    #[Test]
    #[TestDox('A filter on the computed name.formatted finds the user')]
    public function filtersFormattedName(): void
    {
        $this->send('POST', '/Users', ['schemas' => [self::USER], 'userName' => 'ada', 'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace']]);
        $this->send('POST', '/Users', ['schemas' => [self::USER], 'userName' => 'grace', 'name' => ['givenName' => 'Grace', 'familyName' => 'Hopper']]);

        $found = $this->json($this->send('GET', '/Users?filter='.rawurlencode('name.formatted eq "ada lovelace"')));

        self::assertSame([1, 'ada', 'Ada Lovelace'], [$found['totalResults'] ?? null, $found['Resources'][0]['userName'] ?? null, $found['Resources'][0]['name']['formatted'] ?? null]);
    }

    /**
     * @param array<array-key, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $authorization = 'Bearer '.self::TOKEN, ?string $ifMatch = null): Response
    {
        $server = array_filter(['HTTP_AUTHORIZATION' => $authorization, 'HTTP_IF_MATCH' => $ifMatch, 'CONTENT_TYPE' => null === $body ? null : 'application/scim+json']);
        $this->client->request($method, '/scim/v2'.$path, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function idOf(Response $response): string
    {
        $id = $this->json($response)['id'] ?? null;
        self::assertIsString($id, (string) $response->getContent());

        return $id;
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function withoutVersion(array $document): array
    {
        self::assertIsArray($document['meta'] ?? null);
        unset($document['meta']['version']);

        return $document;
    }

    /**
     * @param array<string, mixed> ...$operations
     *
     * @return array<string, mixed>
     */
    private function patchOp(array ...$operations): array
    {
        return ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => $operations];
    }

    private function passwordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }
}
