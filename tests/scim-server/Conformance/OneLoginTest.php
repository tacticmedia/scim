<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Tests\Conformance\Fixtures\IdpClient;

/**
 * OneLogin, var/research/idp-okta-others.md section 2 and the OneLogin quirks of section 7. Its SCIM 1.1 era pages
 * ask for application/json responses, so the requests send and accept application/json.
 */
final class OneLoginTest extends KernelTestCase
{
    use IdpClient;
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const array HEADERS = ['Authorization' => 'Bearer secret-token', 'Accept' => 'application/json'];
    private const array BODY_HEADERS = ['Content-Type' => 'application/json'];

    #[Test]
    #[TestDox('Enable: the userName filter with a non-existent value answers 200 with no users, as application/json (quirks 8, 35)')]
    public function answersConnectionTest(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen')]);

        $response = $this->request('GET', '/Users?filter=userName eq "onelogin-connection-test-9f2c"');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame([0, []], [self::json($response)['totalResults'] ?? null, self::json($response)['Resources'] ?? null]);
    }

    #[Test]
    #[TestDox('Provisioning: filter, POST of the template with an emails object and a custom URN, filter, then PUT with active false (quirks 4, 34, 44, 45)')]
    public function provisionsUser(): void
    {
        $this->seed();
        $template = [
            'schemas' => [self::USER, self::ENTERPRISE],
            'userName' => 'bjensen@example.com',
            'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen'],
            'emails' => ['value' => 'bjensen@example.com', 'type' => 'work', 'primary' => true],
            'active' => true,
            self::ENTERPRISE => ['department' => 'Tour Operations'],
            'urn:scim:my:custom:schema' => ['badge' => '42'],
        ];

        self::assertSame([], self::resources($this->request('GET', '/Users?filter=userName eq "bjensen@example.com"')));
        $created = $this->request('POST', '/Users', $template);
        $id = self::idOf($created);
        self::assertSame([$id], self::ids($this->request('GET', '/Users?filter=userName eq "bjensen@example.com"')));
        $updated = $this->request('PUT', '/Users/'.$id, [...$template, 'active' => false]);

        self::assertSame([201, 'application/json'], [$created->getStatusCode(), $created->headers->get('Content-Type')]);
        self::assertSame([['value' => 'bjensen@example.com', 'type' => 'work', 'primary' => true]], self::json($created)['emails'] ?? null);
        self::assertArrayNotHasKey('urn:scim:my:custom:schema', self::json($created));
        self::assertSame([200, false], [$updated->getStatusCode(), self::json($updated)['active'] ?? null]);
    }

    #[Test]
    #[TestDox('Delete: DELETE answers 204; the user then answers 404 and is in no query result (quirk 26)')]
    public function deletesUser(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen'), self::storedUser('u2', 'jsmith')]);

        self::assertNoContent($this->request('DELETE', '/Users/u1'));

        self::assertScimError($this->request('GET', '/Users/u1', headers: ['Accept' => 'application/scim+json']), 404, null, 'Resource u1 not found.');
        self::assertSame(['u2'], self::ids($this->request('GET', '/Users')));
        self::assertSame([], self::resources($this->request('GET', '/Users?filter=userName eq "bjensen"')));
    }

    #[Test]
    #[TestDox('Group sync: POST /Groups, then Patch Group answers 204 (quirk 18)')]
    public function syncsGroup(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen')]);

        $id = self::idOf($this->request('POST', '/Groups', ['schemas' => [self::GROUP], 'displayName' => 'Tour Guides']));

        self::assertNoContent($this->request('PATCH', '/Groups/'.$id, self::patchOp(['op' => 'add', 'path' => 'members', 'value' => [['value' => 'u1']]])));
        self::assertSame([['value' => 'u1']], ($this->groups->get($id) ?? [])['members'] ?? null);
    }

    #[Test]
    #[TestDox('Pagination: GET /Users?count=2&startIndex=3 answers the third and fourth user (quirk 9)')]
    public function paginates(): void
    {
        $this->seed(array_map(static fn (int $i): array => self::storedUser('u'.$i, 'user'.$i), range(1, 5)));

        $page = self::json($this->request('GET', '/Users?count=2&startIndex=3'));

        self::assertSame([5, 2, 3], [$page['totalResults'] ?? null, $page['itemsPerPage'] ?? null, $page['startIndex'] ?? null]);
        self::assertSame(['u3', 'u4'], array_column((array) ($page['Resources'] ?? []), 'id'));
    }
}
