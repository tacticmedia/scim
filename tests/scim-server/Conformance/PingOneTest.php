<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Tests\Conformance\Fixtures\IdpClient;

/**
 * Request sequences of the PingOne outbound SCIM connector. No source names the request headers; these are the
 * OAuth 2 Bearer Token mode.
 */
final class PingOneTest extends KernelTestCase
{
    use IdpClient;
    private const array HEADERS = ['Authorization' => 'Bearer secret-token'];
    private const array BODY_HEADERS = ['Content-Type' => 'application/scim+json'];

    #[Test]
    #[TestDox('User matching: admin-written filters with capitalized operators match')]
    public function matchesCapitalizedOperators(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen', ['emails' => [['value' => 'bjensen@example.com']]]), self::storedUser('u2', 'jsmith')]);

        self::assertSame(['u1'], self::ids($this->request('GET', '/Users?filter='.rawurlencode('userName Eq "BJensen"'))));
        self::assertSame(['u1'], self::ids($this->request('GET', '/Users?filter='.rawurlencode('Emails Co "@example.com"'))));
        self::assertSame(['u1'], self::ids($this->request('GET', '/Users?filter='.rawurlencode('USERNAME SW "bj" AND emails.value EW ".com"'))));
    }

    #[Test]
    #[TestDox('User matching: a filter on an attribute that the schema does not define answers 400 invalidFilter')]
    public function rejectsUnknownFilterAttribute(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen')]);

        $response = $this->request('GET', '/Users?filter='.rawurlencode('email Co "bjensen@example.com"'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalidFilter', self::json($response)['scimType'] ?? null);
    }

    #[Test]
    #[TestDox('Create: two primary emails keep only the first primary, a custom extension URN is ignored')]
    public function normalizesPrimaryValues(): void
    {
        $this->seed();

        $created = self::json($this->request('POST', '/Users', [
            'schemas' => [self::USER, 'urn:example:ping:custom:2.0:User'],
            'userName' => 'bjensen',
            'emails' => [['value' => 'bjensen@example.com', 'type' => 'work', 'primary' => true], ['value' => 'bjensen@example.com', 'type' => 'home', 'primary' => true]],
            'urn:example:ping:custom:2.0:User' => ['badge' => '42'],
        ]));

        self::assertSame([self::USER], $created['schemas'] ?? null);
        self::assertSame([['value' => 'bjensen@example.com', 'type' => 'work', 'primary' => true], ['value' => 'bjensen@example.com', 'type' => 'home', 'primary' => false]], $created['emails'] ?? null);
        self::assertArrayNotHasKey('urn:example:ping:custom:2.0:User', $created);
    }

    #[Test]
    #[TestDox('Remove Action: Disable sends active false, Delete sends DELETE; both answer 204')]
    public function removesUser(): void
    {
        $this->seed([self::storedUser('u1', 'bjensen', ['active' => true]), self::storedUser('u2', 'jsmith')]);

        self::assertNoContent($this->request('PATCH', '/Users/u1', self::patchOp(['op' => 'replace', 'path' => 'active', 'value' => false])));
        self::assertNoContent($this->request('DELETE', '/Users/u2'));

        self::assertSame(['u1'], self::ids($this->request('GET', '/Users')));
    }
}
