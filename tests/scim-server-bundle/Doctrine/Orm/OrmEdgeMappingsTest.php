<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\KernelInterface;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmQueryContext;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmQueryTranslator;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Object\ObjectPage;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\Plan\Compare;
use TacticMedia\ScimServerBundle\Query\Plan\Exists;
use TacticMedia\ScimServerBundle\Query\Plan\IsPresent;
use TacticMedia\ScimServerBundle\Query\Plan\Node;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures\Crew;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures\CrewEmail;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures\Locker;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures\Staffer;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures\Stamp;

/**
 * Doctrine mappings at the edges: a datetime version, a guid id, int ids outside the column, a ManyToMany element
 * collection, elements with random ids, and a reference on an inverse side. Environment keyset.
 */
final class OrmEdgeMappingsTest extends KernelTestCase
{
    use CreatesOrmStore;

    #[Test]
    #[TestDox('update() leaves a datetime #[ORM\Version] field to the flush')]
    public function datetimeVersion(): void
    {
        $entityManager = self::createSchema();
        $stamp = new Stamp('a');
        $entityManager->persist($stamp);
        $entityManager->flush();

        $mapping = new AttributeLoader()->load(Stamp::class);
        $store = self::store();

        $store->transactional($mapping, static function () use ($store, $mapping, $stamp): void {
            $stamp->name = 'b';
            $store->update($mapping, $stamp);
        });
        $entityManager->clear();

        self::assertSame('b', $entityManager->find(Stamp::class, $stamp->id)?->name);
    }

    #[Test]
    #[TestDox('A string id in a guid column takes eq, pr and sortBy without a text guard')]
    public function guidId(): void
    {
        $ids = $this->lockerIds();
        $mapping = new AttributeLoader()->load(Locker::class);
        $context = new OrmQueryContext(self::getContainer()->get('doctrine.orm.entity_manager'), Locker::class);
        $translator = new OrmQueryTranslator(self::mappings());
        $first = new Compare($mapping->id, ComparisonOperator::Equal, $ids[0]);
        [$ascending, $descending] = [$ids, $ids];
        sort($ascending, \SORT_STRING);
        rsort($descending, \SORT_STRING);

        self::assertSame(['r.id = :scim_p0', 'r.id IS NOT NULL'], [$translator->condition($first, $context), $translator->condition(new IsPresent($mapping->id), $context)]);
        self::assertSame([['CASE WHEN r.id IS NOT NULL THEN 0 ELSE 1 END', 'DESC'], ['r.id', 'DESC'], ['r.id', 'ASC']], $translator->orderBy([$mapping->id], SortOrder::Descending, $context));
        self::assertSame([[$ids[0]], $ascending, $descending], [
            $this->lockers($mapping, $first, []),
            $this->lockers($mapping, new IsPresent($mapping->id), []),
            $this->lockers($mapping, null, [$mapping->id]),
        ]);
    }

    #[Test]
    #[TestDox('An id that the id column cannot hold is unknown without a query, and an id filter with it matches nothing')]
    public function idsOutsideTheColumn(): void
    {
        $this->lockerIds();
        $lockers = new AttributeLoader()->load(Locker::class);
        $badges = self::mappings()->get('Badge');
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        $queries->reset();

        self::assertSame([], self::store()->findMany($lockers, ['not-a-uuid']));
        self::assertSame([], self::store()->findMany($badges, ['b2147483648']));
        self::assertSame([], $queries->getData()['default'] ?? []);
        self::assertSame([], self::store()->findMany($badges, ['b2147483647']));
        self::assertSame([[], []], [
            $this->lockers($lockers, new Compare($lockers->id, ComparisonOperator::Equal, 'not-a-uuid'), []),
            self::store()->query($badges, new ObjectQuery(new Compare($badges->id, ComparisonOperator::Equal, 2147483648), [], SortOrder::Ascending, 1, 10))->objects,
        ]);
    }

    #[Test]
    #[TestDox('sortBy on a ManyToMany element collection gives each owner once, by its key element')]
    public function sortsByManyToManyElements(): void
    {
        $entityManager = self::createSchema();
        $emails = ['p0' => [], 'p1' => ['e1' => 'b@x', 'e4' => 'z@x'], 'p2' => ['e2' => 'a@x', 'e5' => 'y@x'], 'p3' => ['e3' => 'c@x', 'e6' => 'w@x']];

        foreach ($emails as $userName => $values) {
            $crew = new Crew($userName);
            array_walk($values, static fn (string $value, string $id) => $crew->addEmail(new CrewEmail($value, $id)));
            $entityManager->persist($crew);
        }

        $entityManager->flush();
        $entityManager->clear();

        $mapping = new AttributeLoader()->load(Crew::class);
        $collection = $this->at($mapping, 'emails');
        $sortBy = [$collection, $collection->element?->attributes[0] ?? throw new \LogicException('No element.')];
        $pages = array_map(static fn (int $startIndex): ObjectPage => self::store()->query($mapping, new ObjectQuery(null, $sortBy, SortOrder::Descending, $startIndex, 1)), range(1, 4));

        self::assertSame(['p0', 'p3', 'p1', 'p2'], array_merge(...array_map(static fn (ObjectPage $page): array => array_map(static fn (object $crew): string => $crew instanceof Crew ? $crew->userName : '', $page->objects), $pages)));
    }

    #[Test]
    #[TestDox('After a write, an element collection is in identifier order, as the next read loads it')]
    public function writesElementsInIdOrder(): void
    {
        self::createSchema();
        $mapping = new AttributeLoader()->load(Crew::class);
        $store = self::store();
        $crew = new Crew('p1');
        $crew->addEmail(new CrewEmail('b@x', 'e2'));
        $crew->addEmail(new CrewEmail('a@x', 'e1'));

        $store->transactional($mapping, static fn () => $store->insert($mapping, $crew));
        $created = $this->values($crew);
        $store->transactional($mapping, static function () use ($store, $mapping, $crew): void {
            $crew->addEmail(new CrewEmail('c@x', 'e0'));
            $store->update($mapping, $crew);
        });

        self::assertSame([['a@x', 'b@x'], ['c@x', 'a@x', 'b@x']], [$created, $this->values($crew)]);
    }

    #[Test]
    #[TestDox('First use fails for a writable reference on the inverse side of a OneToOne association')]
    public function inverseReferenceFails(): void
    {
        self::createSchema();
        $staffers = $this->staffers();

        $this->expectExceptionObject(new MappingException([Staffer::class.'::$manager: "manager" refers to User, but $manager is the inverse side of a Doctrine association, so writes to it are lost. Map "manager" to a #[ORM\ManyToOne], or to the owning side of a #[ORM\OneToOne].']));

        new OrmObjectStore(self::getContainer()->get('doctrine'), $staffers, new ServiceLocator([IdConverter::class => static fn (): ValueConverter => new IdConverter()]))->find($staffers->get('User'), 'f1');
    }

    #[Test]
    #[TestDox('A filter on a reference on the inverse side of a OneToOne association finds the objects')]
    public function filtersAnInverseReference(): void
    {
        $entityManager = self::createSchema();
        [$boss, $aide] = [new Staffer('boss'), new Staffer('aide')];
        $boss->assistant = $aide;
        $entityManager->persist($boss);
        $entityManager->persist($aide);
        $entityManager->persist(new Staffer('loner'));
        $entityManager->flush();
        $entityManager->clear();

        $staffers = $this->staffers();
        $mapping = $staffers->get('User');
        $context = new OrmQueryContext($entityManager, Staffer::class);
        $condition = new OrmQueryTranslator($staffers)->condition(new Exists($this->at($mapping, 'manager'), new Compare($mapping->id, ComparisonOperator::Equal, $boss->id)), $context);

        $found = $entityManager->createQuery(\sprintf('SELECT r FROM %s r WHERE %s', Staffer::class, $condition))->setParameters($context->parameters())->getResult();

        self::assertSame(['aide'], array_map(static fn (mixed $staffer): string => $staffer instanceof Staffer ? $staffer->userName : '', \is_array($found) ? $found : []));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'keyset'] + $options);
    }

    /**
     * @return list<string> the ids of two lockers in the database
     */
    private function lockerIds(): array
    {
        $entityManager = self::createSchema();
        $lockers = [new Locker('a'), new Locker('b')];
        array_walk($lockers, $entityManager->persist(...));
        $entityManager->flush();
        $entityManager->clear();

        return array_map(static fn (Locker $locker): string => $locker->id, $lockers);
    }

    /**
     * @param list<AttributeMapping> $sortBy
     *
     * @return list<string> the ids of the lockers that query() finds
     */
    private function lockers(ResourceMapping $mapping, ?Node $filter, array $sortBy): array
    {
        return array_map(static fn (object $locker): string => $locker instanceof Locker ? $locker->id : '', self::store()->query($mapping, new ObjectQuery($filter, $sortBy, SortOrder::Descending, 1, 10))->objects);
    }

    /**
     * @return list<string>
     */
    private function values(Crew $crew): array
    {
        return array_values(array_map(static fn (CrewEmail $email): string => $email->value, $crew->getEmails()->toArray()));
    }

    private function staffers(): MappingRegistry
    {
        return new MappingRegistry(new AttributeLoader(), ['User' => Staffer::class]);
    }

    private function at(ResourceMapping $mapping, string $member): AttributeMapping
    {
        return array_find($mapping->attributes, static fn (AttributeMapping $attribute): bool => $attribute->member === $member) ?? throw new \LogicException($member);
    }
}
