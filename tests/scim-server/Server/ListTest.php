<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServer\Server\ServiceProviderConfig;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class ListTest extends KernelTestCase
{
    use ServerFixture;
    private const string LIST_RESPONSE = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    #[Test]
    #[TestDox('GET of an endpoint with a filter answers a ListResponse of the matches; userName matches in any case')]
    public function filters(): void
    {
        $server = $this->fiveUsers();

        $response = $this->send($server, 'GET', '/Users'.self::query(['filter' => 'userName eq "CJones"']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'schemas' => [self::LIST_RESPONSE],
            'totalResults' => 1,
            'itemsPerPage' => 1,
            'startIndex' => 1,
            'Resources' => [[
                'schemas' => [self::USER],
                'id' => 'u3',
                'meta' => [
                    'resourceType' => 'User',
                    'created' => '2026-11-01T08:00:00Z',
                    'lastModified' => '2026-11-01T08:00:00Z',
                    'version' => 'W/"1"',
                    'location' => self::BASE_URL.'/Users/u3',
                ],
                'userName' => 'cjones',
                'displayName' => 'Smith',
                'emails' => [['value' => 'cjones@example.com', 'type' => 'work']],
            ]],
        ], self::json($response));
    }

    #[Test]
    #[TestDox('A filter that matches nothing answers 200 with totalResults 0 and empty Resources (Entra Q22, Okta quirk 7)')]
    public function answersEmptyList(): void
    {
        $server = $this->fiveUsers();

        $response = $this->send($server, 'GET', '/Users'.self::query(['filter' => 'userName eq "5f8c2c9e"']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"schemas":["urn:ietf:params:scim:api:messages:2.0:ListResponse"],"totalResults":0,"itemsPerPage":0,"startIndex":1,"Resources":[]}', $response->getContent());
    }

    /**
     * @return iterable<string, array{array<string, string|int>, list<string>, int}>
     */
    public static function pages(): iterable
    {
        yield 'no parameters: startIndex 1, count maxResults' => [[], ['u1', 'u2', 'u3'], 1];
        yield 'startIndex and count' => [['startIndex' => 2, 'count' => 2], ['u2', 'u3'], 2];
        yield 'startIndex below 1 means 1' => [['startIndex' => -5, 'count' => 1], ['u1'], 1];
        yield 'negative count means 0' => [['count' => -1], [], 1];
        yield 'count above maxResults means maxResults' => [['startIndex' => 2, 'count' => 10], ['u2', 'u3', 'u4'], 2];
        yield 'startIndex past the last match' => [['startIndex' => 9], [], 9];
        yield 'parameter names in any case' => [['STARTINDEX' => 5, 'Count' => '1'], ['u5'], 5];
        yield 'unknown parameters ignored (quirk 39)' => [['cursor' => 'abc', 'startIndex' => '4'], ['u4', 'u5'], 4];
        yield 'empty parameters ignored' => [['startIndex' => '', 'count' => '', 'filter' => '', 'sortBy' => ''], ['u1', 'u2', 'u3'], 1];
    }

    /**
     * @param array<string, string|int> $parameters
     * @param list<string>              $ids
     */
    #[Test]
    #[DataProvider('pages')]
    #[TestDox('Pages: $_dataName')]
    public function pagesResults(array $parameters, array $ids, int $startIndex): void
    {
        $server = $this->fiveUsers(new ServiceProviderConfig(maxResults: 3));

        $response = $this->send($server, 'GET', '/Users'.self::query($parameters));

        self::assertSame($ids, self::ids($response));
        self::assertSame(['totalResults' => 5, 'itemsPerPage' => \count($ids), 'startIndex' => $startIndex], array_intersect_key(self::json($response), ['totalResults' => 0, 'itemsPerPage' => 0, 'startIndex' => 0]));
    }

    /**
     * @return iterable<string, array{array<string, string>, list<string>}>
     */
    public static function sorts(): iterable
    {
        yield 'ascending by default' => [['sortBy' => 'displayName'], ['u2', 'u5', 'u1', 'u4', 'u3']];
        yield 'descending, in any case' => [['sortBy' => 'urn:ietf:params:scim:schemas:core:2.0:User:DISPLAYNAME', 'sortOrder' => 'Descending'], ['u3', 'u4', 'u1', 'u5', 'u2']];
        yield 'sortOrder without sortBy is ignored' => [['sortOrder' => 'descending'], ['u1', 'u2', 'u3', 'u4', 'u5']];
    }

    /**
     * @param array<string, string> $parameters
     * @param list<string>          $ids
     */
    #[Test]
    #[DataProvider('sorts')]
    #[TestDox('Sorts: $_dataName')]
    public function sortsResults(array $parameters, array $ids): void
    {
        $server = $this->fiveUsers();

        self::assertSame($ids, self::ids($this->send($server, 'GET', '/Users'.self::query($parameters))));
    }

    #[Test]
    #[TestDox('attributes applies to each resource of the list (Entra attributes=id)')]
    public function projectsResources(): void
    {
        $server = $this->fiveUsers();

        $response = $this->send($server, 'GET', '/Users'.self::query(['filter' => 'emails[type eq "work"].value eq "cjones@example.com"', 'attributes' => 'id']));

        self::assertSame([['schemas' => [self::USER], 'id' => 'u3']], self::json($response)['Resources']);
    }

    #[Test]
    #[TestDox('A filter on manager compares manager.value, and each resource gets manager.$ref (Entra manager check)')]
    public function filtersOnManager(): void
    {
        $enterprise = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
        $server = $this->server([self::storedUser('u1', 'bjensen'), self::storedUser('u2', 'jsmith', [$enterprise => ['manager' => ['value' => 'u1']]])]);

        $byId = $this->send($server, 'GET', '/Users'.self::query(['filter' => 'id eq "u2" and manager eq "u1"', 'attributes' => 'id']));
        $withManager = $this->send($server, 'GET', '/Users'.self::query(['filter' => 'manager pr', 'attributes' => 'manager']));

        self::assertSame([['schemas' => [self::USER], 'id' => 'u2']], self::json($byId)['Resources']);
        self::assertSame([['schemas' => [self::USER], 'id' => 'u2', $enterprise => ['manager' => ['value' => 'u1', '$ref' => self::BASE_URL.'/Users/u1']]]], self::json($withManager)['Resources']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function resourceTypeFilters(): iterable
    {
        yield 'eq its own name' => ['meta.resourceType eq "User"', 5];
        yield 'eq another name' => ['meta.resourceType eq "Group"', 0];
        yield 'combined with another comparison' => ['meta.resourceType eq "User" and userName sw "a"', 1];
        yield 'a value path on meta (RFC 7644 Table 5)' => ['meta[resourceType eq "User"]', 5];
        yield 'a value path on meta with another sub-attribute' => ['meta[resourceType eq "User" and created pr]', 5];
        yield 'a value path on meta with another name' => ['meta[resourceType eq "Group"]', 0];
    }

    #[Test]
    #[DataProvider('resourceTypeFilters')]
    #[TestDox('A filter on meta.resourceType, which the server sets: $_dataName')]
    public function filtersOnResourceType(string $filter, int $totalResults): void
    {
        $server = $this->fiveUsers();

        self::assertSame($totalResults, self::json($this->send($server, 'GET', '/Users'.self::query(['filter' => $filter])))['totalResults']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'a filter that does not parse' => ['?filter=userName%20eq', 'invalidFilter', 'Expected a comparison value (a JSON string, number, true, false or null), found the end of the filter.'];
        yield 'a filter on an unknown attribute' => ['?filter=shoeSize%20eq%2042', 'invalidFilter', "The attribute 'shoeSize' is not defined for the resource type User."];
        yield 'a filter that is not a string' => ['?filter[]=x', 'invalidValue', 'The parameter "filter" must be a string.'];
        yield 'an unknown sortBy' => ['?sortBy=shoeSize', 'invalidValue', 'The sortBy attribute "shoeSize" is not defined for the resource type User.'];
        yield 'a sortBy that is not a path' => ['?sortBy=name.given.name', 'invalidValue', '"name.given.name" is not a valid sortBy attribute path.'];
        yield 'an unknown sortOrder' => ['?sortBy=userName&sortOrder=up', 'invalidValue', 'The sortOrder "up" is not "ascending" or "descending".'];
        yield 'a startIndex that is not an integer' => ['?startIndex=abc', 'invalidValue', 'The parameter "startIndex" must be an integer.'];
        yield 'a count that is not an integer' => ['?count=1.5', 'invalidValue', 'The parameter "count" must be an integer.'];
        yield 'both attributes and excludedAttributes' => ['?attributes=userName&excludedAttributes=emails', 'invalidValue', 'The parameters "attributes" and "excludedAttributes" are mutually exclusive.'];
        yield 'an invalid comparison that meta.resourceType makes irrelevant (RFC 7644 Table 3)' => [self::query(['filter' => 'meta.resourceType eq "Group" and active gt true']), 'invalidFilter', "The operator 'gt' does not apply to the boolean attribute 'urn:ietf:params:scim:schemas:core:2.0:User:active'."];
        yield 'an unknown attribute that meta.resourceType makes irrelevant' => [self::query(['filter' => 'meta.resourceType eq "User" or foo eq "x"']), 'invalidFilter', "The attribute 'foo' is not defined for the resource type User."];
        yield 'an unknown sortBy with a filter that matches no type' => [self::query(['filter' => 'meta.resourceType eq "Group"', 'sortBy' => 'shoeSize']), 'invalidValue', 'The sortBy attribute "shoeSize" is not defined for the resource type User.'];
        yield 'a sortBy on a never-returned attribute' => ['?sortBy=password', 'invalidValue', 'The sortBy attribute "urn:ietf:params:scim:schemas:core:2.0:User:password" is never returned and cannot be used to sort.'];
        yield 'a sortBy on a never-returned attribute with a filter that matches no type' => [self::query(['filter' => 'meta.resourceType eq "Group"', 'sortBy' => 'password']), 'invalidValue', 'The sortBy attribute "urn:ietf:params:scim:schemas:core:2.0:User:password" is never returned and cannot be used to sort.'];
    }

    #[Test]
    #[DataProvider('invalidQueries')]
    #[TestDox('Answers 400 for $_dataName')]
    public function rejectsInvalidQuery(string $query, string $scimType, string $detail): void
    {
        $server = $this->fiveUsers();

        self::assertScimError($this->send($server, 'GET', '/Users'.$query), 400, $scimType, $detail);
    }

    private function fiveUsers(ServiceProviderConfig $config = new ServiceProviderConfig()): ScimServer
    {
        return $this->server([
            self::storedUser('u1', 'bjensen', ['displayName' => 'Jensen']),
            self::storedUser('u2', 'asmith', ['displayName' => 'Adams']),
            self::storedUser('u3', 'cjones', ['displayName' => 'Smith', 'emails' => [['value' => 'cjones@example.com', 'type' => 'work']]]),
            self::storedUser('u4', 'dlee', ['displayName' => 'Lee']),
            self::storedUser('u5', 'emiller', ['displayName' => 'Baker']),
        ], config: $config);
    }
}
