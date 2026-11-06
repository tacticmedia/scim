<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class SearchTest extends KernelTestCase
{
    use ServerFixture;
    private const string LIST_RESPONSE = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    private const string SEARCH_REQUEST = 'urn:ietf:params:scim:api:messages:2.0:SearchRequest';

    #[Test]
    #[TestDox('POST .search of an endpoint takes the SearchRequest members, in any case, with the semantics of GET')]
    public function searchesEndpoint(): void
    {
        $server = $this->usersAndGroups();

        $response = $this->send($server, 'POST', '/Users/.search', [
            'schemas' => [self::SEARCH_REQUEST],
            'filter' => 'userName sw "c" or userName sw "b"',
            'Attributes' => ['userName', 'displayName'],
            'sortBy' => 'userName',
            'sortOrder' => 'descending',
            'startIndex' => 1,
            'count' => 1,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'schemas' => [self::LIST_RESPONSE],
            'totalResults' => 2,
            'itemsPerPage' => 1,
            'startIndex' => 1,
            'Resources' => [['schemas' => [self::USER], 'id' => 'u3', 'userName' => 'cjones', 'displayName' => 'Carol Jones']],
        ], self::json($response));
    }

    #[Test]
    #[TestDox('POST .search with a body that is not a JSON object answers 400 invalidSyntax')]
    public function rejectsInvalidSearchRequest(): void
    {
        $server = $this->usersAndGroups();

        self::assertScimError($this->send($server, 'POST', '/Users/.search', '["filter"]'), 400, 'invalidSyntax', 'The request body must be a JSON object.');
    }

    /**
     * @return iterable<string, array{array<string, string|int>, list<string>, int}>
     */
    public static function rootPages(): iterable
    {
        yield 'all resources, users first' => [[], ['u1', 'u2', 'u3', 'g1', 'g2'], 5];
        yield 'a page that spans both types' => [['startIndex' => 3, 'count' => 2], ['u3', 'g1'], 5];
        yield 'a page in the second type' => [['startIndex' => 5, 'count' => 2], ['g2'], 5];
        yield 'count 0' => [['count' => 0], [], 5];
        yield 'an attribute that only users define' => [['filter' => 'userName eq "bjensen" or displayName eq "Admins"'], ['u1', 'g2'], 2];
        yield 'not of an undefined attribute' => [['filter' => 'not (userName pr)'], ['g1', 'g2'], 2];
        yield 'ne on an undefined attribute' => [['filter' => 'userName ne "bjensen"'], ['u2', 'u3', 'g1', 'g2'], 4];
        yield 'meta.resourceType (RFC 7644 3.4.2.1)' => [['filter' => '(meta.resourceType eq "Group") or (meta.resourceType eq "Device")'], ['g1', 'g2'], 2];
        yield 'a value path on an undefined attribute' => [['filter' => 'emails[type eq "work"] or members[value eq "u1"]'], ['u1', 'g1'], 2];
        yield 'sortBy sorts across types (RFC 7644 3.4.2.3)' => [['sortBy' => 'displayName'], ['g2', 'u2', 'u1', 'u3', 'g1'], 5];
        yield 'sortBy descending across types' => [['sortBy' => 'displayName', 'sortOrder' => 'descending'], ['g1', 'u3', 'u1', 'u2', 'g2'], 5];
        yield 'a sorted page that spans both types' => [['sortBy' => 'displayName', 'startIndex' => 2, 'count' => 2], ['u2', 'u1'], 5];
        yield 'the last sorted page' => [['sortBy' => 'displayName', 'startIndex' => 4, 'count' => 5], ['u3', 'g1'], 5];
        yield 'a sorted page with count 0' => [['sortBy' => 'displayName', 'count' => 0], [], 5];
        yield 'a sorted page with a startIndex near PHP_INT_MAX' => [['sortBy' => 'displayName', 'startIndex' => '9223372036854775807'], [], 5];
        yield 'sorted with a filter' => [['sortBy' => 'displayName', 'filter' => 'displayName co "o" or userName eq "asmith"'], ['u2', 'u3', 'g1'], 3];
        yield 'a type without the sortBy attribute has no value: first when descending' => [['sortBy' => 'userName', 'sortOrder' => 'descending'], ['g1', 'g2', 'u3', 'u1', 'u2'], 5];
        yield 'a sortBy that no type defines keeps registration order' => [['sortBy' => 'shoeSize'], ['u1', 'u2', 'u3', 'g1', 'g2'], 5];
        yield 'a value path on meta' => [['filter' => 'meta[resourceType eq "Group"]'], ['g1', 'g2'], 2];
        yield 'sortBy meta.resourceType' => [['sortBy' => 'meta.resourceType'], ['g1', 'g2', 'u1', 'u2', 'u3'], 5];
        yield 'sortBy meta.resourceType descending' => [['sortBy' => 'meta.resourceType', 'sortOrder' => 'descending'], ['u1', 'u2', 'u3', 'g1', 'g2'], 5];
    }

    /**
     * @param array<string, string|int> $parameters
     * @param list<string>              $ids
     */
    #[Test]
    #[DataProvider('rootPages')]
    #[TestDox('GET of the server root queries all resource types: $_dataName')]
    public function queriesRoot(array $parameters, array $ids, int $totalResults): void
    {
        $server = $this->usersAndGroups();

        $response = $this->send($server, 'GET', '/'.self::query($parameters));

        self::assertSame($ids, self::ids($response));
        self::assertSame($totalResults, self::json($response)['totalResults']);
    }

    #[Test]
    #[TestDox('POST /.search queries all resource types and projects each resource for its type')]
    public function searchesRoot(): void
    {
        $server = $this->usersAndGroups();

        $response = $this->send($server, 'POST', '/.search', ['filter' => 'displayName sw "a"', 'attributes' => 'displayName']);

        self::assertSame([
            ['schemas' => [self::USER], 'id' => 'u2', 'displayName' => 'Alice Smith'],
            ['schemas' => [self::GROUP], 'id' => 'g2', 'displayName' => 'Admins'],
        ], self::json($response)['Resources']);
    }

    #[Test]
    #[TestDox('POST /.search with sortBy sorts across types and projects each resource for its type')]
    public function searchesRootSorted(): void
    {
        $server = $this->usersAndGroups();

        $response = $this->send($server, 'POST', '/.search', ['sortBy' => 'displayName', 'count' => 2, 'attributes' => 'displayName']);

        self::assertSame([
            'schemas' => [self::LIST_RESPONSE],
            'totalResults' => 5,
            'itemsPerPage' => 2,
            'startIndex' => 1,
            'Resources' => [
                ['schemas' => [self::GROUP], 'id' => 'g2', 'displayName' => 'Admins'],
                ['schemas' => [self::USER], 'id' => 'u2', 'displayName' => 'Alice Smith'],
            ],
        ], self::json($response));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRootFilters(): iterable
    {
        yield 'a value of another type' => ['displayName gt true', "The value true does not fit the string attribute 'urn:ietf:params:scim:schemas:core:2.0:User:displayName'."];
        yield 'an operator that meta.resourceType makes irrelevant for the type that defines the attribute' => ['meta.resourceType eq "Group" and active gt true', "The operator 'gt' does not apply to the boolean attribute 'urn:ietf:params:scim:schemas:core:2.0:User:active'."];
    }

    #[Test]
    #[DataProvider('invalidRootFilters')]
    #[TestDox('A root query still rejects a comparison that a type defines differently: $_dataName')]
    public function rootRejectsInvalidComparison(string $filter, string $detail): void
    {
        $server = $this->usersAndGroups();

        self::assertScimError($this->send($server, 'GET', '/'.self::query(['filter' => $filter])), 400, 'invalidFilter', $detail);
    }

    private function usersAndGroups(): ScimServer
    {
        return $this->server([
            self::storedUser('u1', 'bjensen', ['displayName' => 'Barbara Jensen', 'emails' => [['value' => 'bjensen@example.com', 'type' => 'work']]]),
            self::storedUser('u2', 'asmith', ['displayName' => 'Alice Smith']),
            self::storedUser('u3', 'cjones', ['displayName' => 'Carol Jones']),
        ], [
            self::storedGroup('g1', 'Tour Guides', ['members' => [['value' => 'u1']]]),
            self::storedGroup('g2', 'Admins'),
        ]);
    }
}
