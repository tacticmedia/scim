<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\SchemaUrn;

final class InMemoryResourceRepositoryTest extends KernelTestCase
{
    private const string USER = SchemaUrn::User->value;

    #[Test]
    #[TestDox('create() assigns a UUID v7 id, meta.created and meta.lastModified in UTC to the second, and version W/"1"')]
    public function createAssignsIdAndMeta(): void
    {
        $repository = $this->repository(new MockClock('2026-11-05 21:20:30.987654', 'Australia/Sydney'));

        $created = $repository->create(['schemas' => [self::USER], 'userName' => 'bjensen']);

        $id = $created['id'];
        self::assertIsString($id);
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($id));
        self::assertSame(Uuid::fromString($id)->toRfc4122(), $id);
        self::assertSame([
            'schemas' => [self::USER],
            'id' => $id,
            'meta' => ['created' => '2026-11-05T10:20:30Z', 'lastModified' => '2026-11-05T10:20:30Z', 'version' => 'W/"1"'],
            'userName' => 'bjensen',
        ], $created);
        self::assertSame($created, $repository->get($id));
    }

    #[Test]
    #[TestDox('create() ignores the id and meta of the input and gives each resource its own id')]
    public function createIgnoresInputIdAndMeta(): void
    {
        $repository = $this->repository(new MockClock('2026-11-05T10:20:30Z'));
        $input = ['id' => 'client', 'userName' => 'bjensen', 'meta' => ['resourceType' => 'User', 'version' => 'W/"9"']];

        $first = $repository->create($input);
        $second = $repository->create($input);

        self::assertNotSame('client', $first['id']);
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(['created' => '2026-11-05T10:20:30Z', 'lastModified' => '2026-11-05T10:20:30Z', 'version' => 'W/"1"'], $first['meta']);
        self::assertNull($repository->get('client'));
    }

    #[Test]
    #[TestDox('replace() stores the new state with the stored id and meta.created, a new meta.lastModified and the next version')]
    public function replaceStoresNewState(): void
    {
        $clock = new MockClock('2026-11-05T10:00:00Z');
        $repository = $this->repository($clock);
        $id = (string) $repository->create(['schemas' => [self::USER], 'userName' => 'bjensen', 'title' => 'Tour Guide'])['id'];
        $clock->sleep(90);

        $replaced = $repository->replace($id, [
            'schemas' => [self::USER],
            'id' => 'other',
            'userName' => 'babs',
            'meta' => ['created' => '1999-01-01T00:00:00Z', 'version' => 'W/"99"'],
        ]);

        self::assertSame([
            'schemas' => [self::USER],
            'id' => $id,
            'meta' => ['created' => '2026-11-05T10:00:00Z', 'lastModified' => '2026-11-05T10:01:30Z', 'version' => 'W/"2"'],
            'userName' => 'babs',
        ], $replaced);
        self::assertSame($replaced, $repository->get($id));
        self::assertNull($repository->get('other'));
        self::assertSame('W/"3"', $this->version($repository->replace($id, ['userName' => 'babs'])));
    }

    #[Test]
    #[TestDox('delete() removes the resource from get() and list()')]
    public function deleteRemovesResource(): void
    {
        $repository = $this->repository(new MockClock());
        $kept = $repository->create(['userName' => 'kept']);
        $id = (string) $repository->create(['userName' => 'deleted'])['id'];

        $repository->delete($id);

        self::assertNull($repository->get($id));
        self::assertSame([$kept], $repository->list(new ListQuery())->resources);
    }

    #[Test]
    #[TestDox('replace() and delete() with the stored version write')]
    public function writesWithTheStoredVersion(): void
    {
        $repository = $this->repository(new MockClock());
        $id = (string) $repository->create(['userName' => 'bjensen'])['id'];

        self::assertSame('W/"2"', $this->version($repository->replace($id, ['userName' => 'babs'], 'W/"1"')));
        $repository->delete($id, 'W/"2"');
        self::assertNull($repository->get($id));
    }

    /**
     * @return iterable<string, array{\Closure(InMemoryResourceRepository): mixed}>
     */
    public static function writesWithAnotherVersion(): iterable
    {
        yield 'replace' => [static fn (InMemoryResourceRepository $repository): array => $repository->replace('u1', ['userName' => 'x'], 'W/"1"')];
        yield 'delete' => [static fn (InMemoryResourceRepository $repository) => $repository->delete('u1', 'W/"1"')];
        yield 'replace an unknown id' => [static fn (InMemoryResourceRepository $repository): array => $repository->replace('u9', ['userName' => 'x'], 'W/"1"')];
    }

    /**
     * @param \Closure(InMemoryResourceRepository): mixed $write
     */
    #[Test]
    #[DataProvider('writesWithAnotherVersion')]
    #[TestDox('Answers 412 and keeps the stored resource when the version differs: $_dataName')]
    public function answersPreconditionFailed(\Closure $write): void
    {
        $stored = ['id' => 'u1', 'meta' => ['version' => 'W/"2"'], 'userName' => 'bjensen'];
        $repository = $this->repository(new MockClock(), [$stored]);

        try {
            $write($repository);
            self::fail('No ScimException.');
        } catch (ScimException $scimException) {
            self::assertEquals(ScimException::preconditionFailed('Failed to update. Resource has changed on the server.'), $scimException);
        }

        self::assertSame($stored, $repository->get('u1'));
    }

    /**
     * @return iterable<string, array{string, \Closure(InMemoryResourceRepository): mixed}>
     */
    public static function writesToUnknownIds(): iterable
    {
        yield 'replace an unknown id' => ['u9', static fn (InMemoryResourceRepository $repository): array => $repository->replace('u9', ['userName' => 'x'])];
        yield 'delete an unknown id' => ['u9', static fn (InMemoryResourceRepository $repository) => $repository->delete('u9')];
    }

    /**
     * @param \Closure(InMemoryResourceRepository): mixed $write
     */
    #[Test]
    #[DataProvider('writesToUnknownIds')]
    #[TestDox('Answers 404: $_dataName')]
    public function answersNotFound(string $id, \Closure $write): void
    {
        $repository = $this->repository(new MockClock(), [['id' => 'u1', 'userName' => 'bjensen']]);

        $this->expectExceptionObject(ScimException::notFound(\sprintf('Resource %s not found.', $id)));

        $write($repository);
    }

    #[Test]
    #[TestDox('get() returns a seeded document as given, and null for an unknown id')]
    public function getReturnsSeededDocument(): void
    {
        $seed = ['schemas' => [self::USER], 'id' => '2819c223', 'userName' => 'bjensen', 'unknown' => ['kept' => true]];
        $repository = $this->repository(new MockClock(), [$seed]);

        self::assertSame($seed, $repository->get('2819c223'));
        self::assertNull($repository->get('2819C223'));
    }

    /**
     * @return iterable<string, array{mixed, array<string, string>}>
     */
    public static function seededMeta(): iterable
    {
        yield 'numbered version' => [
            ['resourceType' => 'User', 'created' => '2011-08-01T18:29:49.793Z', 'lastModified' => '2011-08-02T00:00:00Z', 'version' => 'W/"7"'],
            ['resourceType' => 'User', 'created' => '2011-08-01T18:29:49.793Z', 'lastModified' => '2026-11-05T10:00:00Z', 'version' => 'W/"8"'],
        ];
        yield 'opaque version' => [
            ['created' => '2011-08-01T18:29:49Z', 'version' => 'W/"a330bc54f0671c9"'],
            ['created' => '2011-08-01T18:29:49Z', 'version' => 'W/"1"', 'lastModified' => '2026-11-05T10:00:00Z'],
        ];
        yield 'no meta' => [null, ['created' => '2026-11-05T10:00:00Z', 'lastModified' => '2026-11-05T10:00:00Z', 'version' => 'W/"1"']];
        yield 'meta not an object' => ['x', ['created' => '2026-11-05T10:00:00Z', 'lastModified' => '2026-11-05T10:00:00Z', 'version' => 'W/"1"']];
    }

    /**
     * @param array<string, string> $expected
     */
    #[Test]
    #[DataProvider('seededMeta')]
    #[TestDox('replace() of a seeded document keeps its meta and sets the next version: $_dataName')]
    public function replaceOfSeededDocument(mixed $meta, array $expected): void
    {
        $repository = $this->repository(new MockClock('2026-11-05T10:00:00Z'), [['id' => 'u1', 'meta' => $meta]]);

        self::assertSame($expected, $repository->replace('u1', ['userName' => 'bjensen'])['meta']);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function invalidSeeds(): iterable
    {
        yield 'no id' => [[['userName' => 'bjensen']]];
        yield 'empty id' => [[['id' => '']]];
        yield 'id not a string' => [[['id' => 1]]];
        yield 'repeated id' => [[['id' => 'u1'], ['id' => 'u1']]];
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    #[Test]
    #[DataProvider('invalidSeeds')]
    #[TestDox('Rejects seeded documents: $_dataName')]
    public function rejectsInvalidSeeds(array $documents): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Every seeded document needs a unique, non-empty string "id".'));

        $this->repository(new MockClock(), $documents);
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    private function repository(MockClock $clock, array $documents = []): InMemoryResourceRepository
    {
        return new InMemoryResourceRepository(CoreResourceTypes::user(), $clock, $documents);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function version(array $document): mixed
    {
        return \is_array($document['meta']) ? $document['meta']['version'] ?? null : null;
    }
}
