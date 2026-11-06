<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * RFC 9865 cursors as the Keycloak plugin shebang-labs/scim-outbound-for-keycloak requests them: the first page with an
 * empty cursor, then nextCursor until a page has none. Default configuration: cursors on, the key is kernel.secret.
 */
final class CursorPaginationTest extends WebTestCase
{
    use ScimClient;

    #[Test]
    #[TestDox('?cursor&count=2, then nextCursor: every user once in id order, no startIndex, a new cursor each page')]
    public function walksAllUsers(): void
    {
        $ids = $this->users('u1', 'u2', 'u3', 'u4', 'u5');

        $pages = $this->walk('/scim/v2/Users?count=2');

        self::assertSame($ids, array_merge(...array_column($pages, 'ids')));
        self::assertSame([[5, 2, true], [5, 2, true], [5, 1, false]], array_map(static fn (array $page): array => [$page['totalResults'], $page['itemsPerPage'], $page['hasNext']], $pages));
        self::assertSame([false], array_unique(array_column($pages, 'hasStartIndex')));
    }

    #[Test]
    #[TestDox('A deleted last resource of a page neither skips nor repeats a resource on the next page')]
    public function deleteBetweenPages(): void
    {
        $ids = $this->users('b', 'd', 'f', 'h', 'j');
        $first = self::json($this->send('GET', '/scim/v2/Users?sortBy=userName&count=2&cursor'));
        self::assertNoContent($this->send('DELETE', '/scim/v2/Users/'.$ids[1]));

        $rest = $this->walk('/scim/v2/Users?sortBy=userName&count=2', $this->cursor($first));

        self::assertSame([$ids[0], $ids[1]], array_column($this->list($first['Resources'] ?? null), 'id'));
        self::assertSame([[$ids[2], $ids[3]], [$ids[4]]], array_column($rest, 'ids'));
        self::assertSame([4, 4], array_column($rest, 'totalResults'));
    }

    #[Test]
    #[TestDox('A resource created before the position does not repeat a resource on the next page')]
    public function createBetweenPages(): void
    {
        $ids = $this->users('b', 'd', 'f', 'h', 'j');
        $first = self::json($this->send('GET', '/scim/v2/Users?sortBy=userName&count=2&cursor'));
        $this->users('a');

        $rest = $this->walk('/scim/v2/Users?sortBy=userName&count=2', $this->cursor($first));

        self::assertSame([[$ids[2], $ids[3]], [$ids[4]]], array_column($rest, 'ids'));
        self::assertSame([6, 6], array_column($rest, 'totalResults'));
    }

    /**
     * @return list<string> the ids, in creation order
     */
    private function users(string ...$userNames): array
    {
        return array_map(fn (string $userName): string => self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => $userName])), array_values($userNames));
    }

    /**
     * Requests $uri with the cursor, then with each nextCursor, at most 10 pages.
     *
     * @return list<array{ids: list<mixed>, totalResults: mixed, itemsPerPage: mixed, hasNext: bool, hasStartIndex: bool}>
     */
    private function walk(string $uri, string $cursor = ''): array
    {
        $pages = [];
        $seen = [];

        do {
            $page = self::json($this->send('GET', $uri.'&cursor='.rawurlencode($cursor)));
            $next = $page['nextCursor'] ?? null;
            $pages[] = ['ids' => array_column($this->list($page['Resources'] ?? null), 'id'), 'totalResults' => $page['totalResults'] ?? null, 'itemsPerPage' => $page['itemsPerPage'] ?? null, 'hasNext' => null !== $next, 'hasStartIndex' => \array_key_exists('startIndex', $page)];
            self::assertNotContains($next, $seen, 'The Keycloak plugin aborts on a repeated cursor.');
            $seen[] = $cursor = \is_string($next) ? $next : '';
        } while ('' !== $cursor && \count($pages) < 10);

        return $pages;
    }

    /**
     * @param array<array-key, mixed> $page
     */
    private function cursor(array $page): string
    {
        self::assertIsString($page['nextCursor'] ?? null);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $page['nextCursor']);

        return $page['nextCursor'];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function list(mixed $resources): array
    {
        self::assertIsList($resources);

        return array_values(array_filter($resources, \is_array(...)));
    }
}
