<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Tests\Conformance\Fixtures\IdpClient;

/**
 * JumpCloud, var/research/idp-okta-others.md section 3 and the JumpCloud quirks of section 7. The report names no
 * request headers and no update method; these are Bearer, application/scim+json and PUT.
 */
final class JumpCloudTest extends KernelTestCase
{
    use IdpClient;
    private const array HEADERS = ['Authorization' => 'Bearer secret-token'];
    private const array BODY_HEADERS = ['Content-Type' => 'application/scim+json'];

    #[Test]
    #[TestDox('Test connection: GET /Users answers 200')]
    public function answersConnectionTest(): void
    {
        $this->seed();

        self::assertEmptyListResponse($this->request('GET', '/Users'));
    }

    #[Test]
    #[TestDox('Activation tests: create, get, update and delete a user with a fresh email, then a group (quirks 19, 26, 46)')]
    public function runsActivationTests(): void
    {
        $this->seed();

        $created = $this->request('POST', '/Users', ['schemas' => [self::USER], 'userName' => 'jc-test@example.com', 'emails' => [['value' => 'jc-test@example.com', 'type' => 'work', 'primary' => true]], 'active' => true]);
        $userId = self::idOf($created);
        $user = self::json($this->request('GET', '/Users/'.$userId));
        $updated = $this->request('PUT', '/Users/'.$userId, [...$user, 'displayName' => 'JumpCloud Test']);
        $userDeleted = $this->request('DELETE', '/Users/'.$userId);
        $groupId = self::idOf($this->request('POST', '/Groups', ['schemas' => [self::GROUP], 'displayName' => 'JumpCloud Test Group']));
        $group = self::json($this->request('GET', '/Groups/'.$groupId));
        $groupUpdated = $this->request('PUT', '/Groups/'.$groupId, [...$group, 'displayName' => 'JumpCloud Test Group 2']);

        self::assertSame([201, 200, 204, 200], [$created->getStatusCode(), $updated->getStatusCode(), $userDeleted->getStatusCode(), $groupUpdated->getStatusCode()]);
        self::assertSame('JumpCloud Test', self::json($updated)['displayName'] ?? null);
        self::assertNoContent($this->request('DELETE', '/Groups/'.$groupId));
        self::assertSame([null, null], [$this->users->get($userId), $this->groups->get($groupId)]);
    }

    #[Test]
    #[TestDox('User matching: email, then username, each with exactly one result (quirk 47)')]
    public function matchesByEmailThenUserName(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen', ['emails' => [['value' => 'bjensen@example.com', 'type' => 'work']]]), self::storedUser('u2', 'jsmith', ['emails' => [['value' => 'jsmith@example.com']]])]);

        self::assertSame(['u1'], self::ids($this->request('GET', '/Users?filter='.rawurlencode('emails eq "BJensen@example.com"'))));
        self::assertSame(['u1'], self::ids($this->request('GET', '/Users?filter='.rawurlencode('emails.value eq "bjensen@example.com"'))));
        self::assertSame(['u2'], self::ids($this->request('GET', '/Users?filter='.rawurlencode('userName eq "jsmith"'))));
    }

    #[Test]
    #[TestDox('Group takeover: the displayName lookup finds the existing group; deleting it leaves the members (quirks 10, 27)')]
    public function takesOverGroup(): void
    {
        $user = self::storedUser('u1', 'bjensen', ['active' => true]);
        $this->seed([$user], [self::storedGroup('g1', 'Engineering', ['members' => [['value' => 'u1']]])]);

        self::assertSame(['g1'], self::ids($this->request('GET', '/Groups?filter='.rawurlencode('displayName eq "Engineering"'))));
        self::assertNoContent($this->request('DELETE', '/Groups/g1'));
        self::assertSame($user, $this->users->get('u1'));
    }
}
