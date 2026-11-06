<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServer\Server\ServiceProviderConfig;
use TacticMedia\ScimServer\Tests\Server\Fixtures\RecordingRepository;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;
use TacticMedia\ScimServer\Tests\Server\Fixtures\SwitchableCursorRepository;

/**
 * RFC 9865 through ScimServer with a cursor key.
 */
final class CursorPaginationTest extends KernelTestCase
{
    use ServerFixture;
    private const string LIST_RESPONSE = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    private const string KEY = 'cursor-key';
    private const string INVALID_CURSOR = 'The cursor is not valid for this query.';

    #[Test]
    #[TestDox('?cursor starts a traversal; each page has nextCursor and no startIndex, the last page no nextCursor')]
    public function traverses(): void
    {
        $server = $this->fiveUsers();

        $pages = $this->traverse($server, '/Users', ['count' => 2]);

        self::assertSame([['u1', 'u2'], ['u3', 'u4'], ['u5']], array_map(self::ids(...), $pages));
        self::assertSame(['schemas', 'totalResults', 'itemsPerPage', 'nextCursor', 'Resources'], array_keys(self::json($pages[0])));
        self::assertSame(['schemas' => [self::LIST_RESPONSE], 'totalResults' => 5, 'itemsPerPage' => 1], array_intersect_key(self::json($pages[2]), ['schemas' => 0, 'totalResults' => 0, 'itemsPerPage' => 0, 'nextCursor' => 0]));
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $this->nextCursor($pages[0]));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function sortedQueries(): iterable
    {
        yield 'sortBy ascending, absent values last' => [['sortBy' => 'displayName']];
        yield 'sortBy descending, absent values first' => [['sortBy' => 'displayName', 'sortOrder' => 'descending']];
        yield 'sortBy and filter' => [['sortBy' => 'displayName', 'filter' => 'userName ne "dlee"']];
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('sortedQueries')]
    #[TestDox('Cursor pages follow the order of index pages: $_dataName')]
    public function followsIndexOrder(array $query): void
    {
        $server = $this->fiveUsers();

        $byIndex = self::ids($this->send($server, 'GET', '/Users'.self::query($query)));

        self::assertSame($byIndex, array_merge(...array_map(self::ids(...), $this->traverse($server, '/Users', [...$query, 'count' => 2]))));
    }

    #[Test]
    #[TestDox('POST .search takes "cursor" from the SearchRequest; GET continues a search')]
    public function searches(): void
    {
        $server = $this->fiveUsers();

        $first = $this->send($server, 'POST', '/Users/.search', ['cursor' => '', 'count' => 2, 'filter' => 'userName pr']);
        $second = $this->send($server, 'POST', '/Users/.search', ['cursor' => $this->nextCursor($first), 'count' => 2, 'filter' => 'userName pr']);
        $third = $this->send($server, 'GET', '/Users'.self::query(['cursor' => $this->nextCursor($second), 'count' => 2, 'filter' => 'userName pr']));

        self::assertSame([['u1', 'u2'], ['u3', 'u4'], ['u5']], [self::ids($first), self::ids($second), self::ids($third)]);
        self::assertArrayNotHasKey('nextCursor', self::json($third));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function changedQueries(): iterable
    {
        yield 'another filter' => ['/Users', ['filter' => 'userName pr']];
        yield 'another sortBy' => ['/Users', ['sortBy' => 'userName']];
        yield 'another sortOrder' => ['/Users', ['sortOrder' => 'descending']];
        yield 'another endpoint' => ['/Groups', []];
        yield 'the server root' => ['/', []];
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('changedQueries')]
    #[TestDox('A cursor with $_dataName answers 400 invalidCursor')]
    public function rejectsChangedQuery(string $path, array $query): void
    {
        $server = $this->fiveUsers();
        $first = $this->send($server, 'GET', '/Users'.self::query(['cursor' => '', 'count' => 2, 'sortBy' => 'displayName']));

        $response = $this->send($server, 'GET', $path.self::query(['sortBy' => 'displayName', ...$query, 'cursor' => $this->nextCursor($first)]));

        self::assertScimError($response, 400, 'invalidCursor', self::INVALID_CURSOR);
    }

    #[Test]
    #[TestDox('A cursor with another count, attributes or excludedAttributes continues')]
    public function acceptsChangedCountAndProjection(): void
    {
        $server = $this->fiveUsers();
        $first = $this->send($server, 'GET', '/Users?cursor&count=2');

        $response = $this->send($server, 'GET', '/Users'.self::query(['cursor' => $this->nextCursor($first), 'count' => 1, 'attributes' => 'userName']));

        self::assertSame([['schemas' => [self::USER], 'id' => 'u3', 'userName' => 'cjones']], self::json($response)['Resources']);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null, string, string}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'a cursor that the server did not issue' => ['GET', '/Users?cursor=abc', null, 'invalidCursor', self::INVALID_CURSOR];
        yield 'a cursor that is not a string (query)' => ['GET', '/Users?cursor[]=abc', null, 'invalidCursor', self::INVALID_CURSOR];
        yield 'a cursor that is not a string (SearchRequest)' => ['POST', '/Users/.search', ['cursor' => 5], 'invalidCursor', self::INVALID_CURSOR];
        yield 'a count that is not an integer' => ['GET', '/Users?cursor&count=abc', null, 'invalidCount', 'The parameter "count" must be an integer.'];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('invalidRequests')]
    #[TestDox('Answers 400 for $_dataName')]
    public function rejectsInvalidRequest(string $method, string $uri, ?array $body, string $scimType, string $detail): void
    {
        self::assertScimError($this->send($this->fiveUsers(), $method, $uri, $body), 400, $scimType, $detail);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function countZeroQueries(): iterable
    {
        yield 'a CursorPaginatedRepository' => ['/Users?cursor&count=0', true];
        yield 'a repository without CursorPaginatedRepository' => ['/Users?cursor&count=0', false];
        yield 'the root' => ['/?cursor&count=0', true];
        yield 'the root with sortBy' => ['/?cursor&count=0&sortBy=displayName', true];
    }

    #[Test]
    #[DataProvider('countZeroQueries')]
    #[TestDox('count=0 on $_dataName answers totalResults only, without nextCursor')]
    public function answersCountZero(string $uri, bool $positioned): void
    {
        $server = $positioned ? $this->fiveUsers() : $this->serverWith(new RecordingRepository(new InMemoryResourceRepository(CoreResourceTypes::user(), new MockClock(), $this->users())));

        $response = $this->send($server, 'GET', $uri);

        self::assertSame('{"schemas":["urn:ietf:params:scim:api:messages:2.0:ListResponse"],"totalResults":5,"itemsPerPage":0,"Resources":[]}', $response->getContent());
    }

    /**
     * @return iterable<string, array{string, bool, int}>
     */
    public static function oversizedPages(): iterable
    {
        yield 'index' => ['/Users?count=1', true, 1];
        yield 'a cursor by position' => ['/Users?cursor&count=1', true, 1];
        yield 'a cursor by offset' => ['/Users?cursor&count=1', false, 1];
        yield 'a cursor with count=0' => ['/Users?cursor&count=0', true, 0];
    }

    #[Test]
    #[DataProvider('oversizedPages')]
    #[TestDox('A repository page longer than count is cut: $_dataName')]
    public function cutsOversizedPage(string $uri, bool $native, int $itemsPerPage): void
    {
        $repository = new SwitchableCursorRepository(new InMemoryResourceRepository(CoreResourceTypes::user(), new MockClock(), $this->users()));
        $repository->native = $native;
        $repository->oversized = true;

        self::assertSame($itemsPerPage, self::json($this->send($this->serverWith($repository), 'GET', $uri))['itemsPerPage'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function longSortValues(): iterable
    {
        yield '250 emoji' => [str_repeat("\u{1F600}", 249)];
        yield '3500 ASCII characters' => [str_repeat('x', 3499)];
    }

    #[Test]
    #[DataProvider('longSortValues')]
    #[TestDox('A traversal sorted by values of $_dataName reaches the last page')]
    public function traversesLongSortValues(string $suffix): void
    {
        $users = array_map(static fn (string $letter): array => self::storedUser('u'.$letter, $letter, ['displayName' => $letter.$suffix]), ['a', 'b', 'c']);
        $server = $this->server($users, cursorKey: self::KEY);

        self::assertSame([['ua'], ['ub'], ['uc']], array_map(self::ids(...), $this->traverse($server, '/Users', ['count' => 1, 'sortBy' => 'displayName'])));
    }

    #[Test]
    #[TestDox('An empty cursor with startIndex pages by index')]
    public function prefersStartIndexOverEmptyCursor(): void
    {
        $response = $this->send($this->fiveUsers(), 'GET', '/Users?cursor&startIndex=4');

        self::assertSame(['totalResults' => 5, 'itemsPerPage' => 2, 'startIndex' => 4], array_intersect_key(self::json($response), ['totalResults' => 0, 'itemsPerPage' => 0, 'startIndex' => 0, 'nextCursor' => 0]));
    }

    #[Test]
    #[TestDox('Deleting the last resource of a page skips nothing; a resource created before the position is in no later page')]
    public function survivesChangesBetweenPages(): void
    {
        $server = $this->fiveUsers();
        $first = $this->send($server, 'GET', '/Users?cursor&count=2&sortBy=userName');

        $this->users->delete('u1');
        $this->users->create(['schemas' => [self::USER], 'userName' => 'aaron']);

        $rest = $this->traverse($server, '/Users', ['cursor' => $this->nextCursor($first), 'count' => 2, 'sortBy' => 'userName']);

        self::assertSame([['u2', 'u1'], ['u3', 'u4'], ['u5']], [self::ids($first), ...array_map(self::ids(...), $rest)]);
    }

    #[Test]
    #[TestDox('With cursorTimeoutSeconds, a cursor older than that answers 400 expiredCursor')]
    public function expires(): void
    {
        $server = $this->fiveUsers(new ServiceProviderConfig(cursorTimeoutSeconds: 60));
        $first = $this->send($server, 'GET', '/Users?cursor&count=2');

        $this->clock->sleep(61);

        self::assertScimError($this->send($server, 'GET', '/Users?count=2&cursor='.$this->nextCursor($first)), 400, 'expiredCursor', 'The cursor has expired. Request the first page again.');
    }

    /**
     * @return iterable<string, array{\Closure(InMemoryResourceRepository): ResourceRepository}>
     */
    public static function offsetRepositories(): iterable
    {
        yield 'a repository without CursorPaginatedRepository' => [static fn (InMemoryResourceRepository $inner): ResourceRepository => new RecordingRepository($inner)];
        yield 'a CursorPaginatedRepository that declines the query' => [static function (InMemoryResourceRepository $inner): ResourceRepository {
            $repository = new SwitchableCursorRepository($inner);
            $repository->native = false;

            return $repository;
        }];
    }

    /**
     * @param \Closure(InMemoryResourceRepository): ResourceRepository $repository
     */
    #[Test]
    #[DataProvider('offsetRepositories')]
    #[TestDox('The server pages by offset for $_dataName')]
    public function pagesByOffset(\Closure $repository): void
    {
        $server = $this->serverWith($repository(new InMemoryResourceRepository(CoreResourceTypes::user(), new MockClock(), $this->users())));

        $pages = $this->traverse($server, '/Users', ['count' => 2, 'sortBy' => 'displayName']);

        self::assertSame([['u2', 'u5'], ['u1', 'u4'], ['u3']], array_map(self::ids(...), $pages));
    }

    #[Test]
    #[TestDox('A repository position that the repository later declines answers 400 invalidCursor')]
    public function rejectsDeclinedPosition(): void
    {
        $repository = new SwitchableCursorRepository(new InMemoryResourceRepository(CoreResourceTypes::user(), new MockClock(), $this->users()));
        $server = $this->serverWith($repository);
        $first = $this->send($server, 'GET', '/Users?cursor&count=2');

        $repository->native = false;

        self::assertScimError($this->send($server, 'GET', '/Users?count=2&cursor='.$this->nextCursor($first)), 400, 'invalidCursor', self::INVALID_CURSOR);
    }

    #[Test]
    #[TestDox('A filter that no resource of the type can match answers an empty page')]
    public function answersFoldedFilter(): void
    {
        $response = $this->send($this->fiveUsers(), 'GET', '/Users'.self::query(['cursor' => '', 'filter' => 'meta.resourceType eq "Group"']));

        self::assertSame(['totalResults' => 0, 'itemsPerPage' => 0, 'Resources' => []], array_intersect_key(self::json($response), ['totalResults' => 0, 'itemsPerPage' => 0, 'nextCursor' => 0, 'Resources' => 0]));
    }

    /**
     * @return iterable<string, array{array<string, string>, list<list<string>>}>
     */
    public static function rootQueries(): iterable
    {
        yield 'registration order' => [[], [['u1', 'u2'], ['u3', 'u4'], ['u5', 'g1'], ['g2']]];
        yield 'sortBy across types' => [['sortBy' => 'displayName'], [['u2', 'g2'], ['u5', 'u1'], ['u4', 'g1'], ['u3']]];
    }

    /**
     * @param array<string, string> $query
     * @param list<list<string>>    $pages
     */
    #[Test]
    #[DataProvider('rootQueries')]
    #[TestDox('The server root pages by offset cursors: $_dataName')]
    public function pagesRoot(array $query, array $pages): void
    {
        $server = $this->server($this->users(), [self::storedGroup('g1', 'Tour Guides'), self::storedGroup('g2', 'Admins')], cursorKey: self::KEY);

        self::assertSame($pages, array_map(self::ids(...), $this->traverse($server, '/', [...$query, 'count' => 2])));
        self::assertSame($pages, array_map(self::ids(...), $this->traverse($server, '/', [...$query, 'count' => 2], 'POST')));
    }

    #[Test]
    #[TestDox('/ServiceProviderConfig publishes cursor pagination and cursorTimeout')]
    public function publishesPagination(): void
    {
        $server = $this->fiveUsers(new ServiceProviderConfig(maxResults: 50, cursorTimeoutSeconds: 600));

        self::assertSame(
            ['cursor' => true, 'index' => true, 'defaultPaginationMethod' => 'index', 'defaultPageSize' => 50, 'maxPageSize' => 50, 'cursorTimeout' => 600],
            self::json($this->send($server, 'GET', '/ServiceProviderConfig'))['pagination'] ?? null,
        );
    }

    /**
     * Follows nextCursor from an empty cursor until a page has none.
     *
     * @param array<string, string|int> $query
     *
     * @return list<Response>
     */
    private function traverse(ScimServer $server, string $path, array $query, string $method = 'GET'): array
    {
        $pages = [];
        $cursor = $query['cursor'] ?? '';

        while (\is_string($cursor) && \count($pages) < 10) {
            $response = 'GET' === $method
                ? $this->send($server, 'GET', $path.self::query([...$query, 'cursor' => $cursor]))
                : $this->send($server, 'POST', rtrim($path, '/').'/.search', [...$query, 'cursor' => $cursor]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $pages[] = $response;
            $cursor = self::json($response)['nextCursor'] ?? null;
        }

        return $pages;
    }

    private function nextCursor(Response $response): string
    {
        $cursor = self::json($response)['nextCursor'] ?? null;
        self::assertIsString($cursor, (string) $response->getContent());

        return $cursor;
    }

    private function fiveUsers(ServiceProviderConfig $config = new ServiceProviderConfig()): ScimServer
    {
        return $this->server($this->users(), config: $config, cursorKey: self::KEY);
    }

    private function serverWith(ResourceRepository $users): ScimServer
    {
        return new ScimServer([new RegisteredResourceType(CoreResourceTypes::user(), $users)], cursorKey: self::KEY);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function users(): array
    {
        return [
            self::storedUser('u1', 'bjensen', ['displayName' => 'Jensen']),
            self::storedUser('u2', 'asmith', ['displayName' => 'Adams']),
            self::storedUser('u3', 'cjones'),
            self::storedUser('u4', 'dlee', ['displayName' => 'Lee']),
            self::storedUser('u5', 'emiller', ['displayName' => 'Baker']),
        ];
    }
}
