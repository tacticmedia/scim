<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\CursorPage;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServerBundle\Object\Instantiator;
use TacticMedia\ScimServerBundle\Object\ObjectResourceRepository;
use TacticMedia\ScimServerBundle\Query\QueryPlanner;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Directory;
use TacticMedia\ScimServerBundle\Tests\Fixtures\ArrayObjectStore;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Account;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Team;

/**
 * ObjectResourceRepository::listAfter().
 */
final class ObjectResourceRepositoryCursorTest extends KernelTestCase
{
    use CreatesDocumentMapper;
    use CreatesSchema;
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    #[Test]
    #[TestDox('Over the ORM store, pages follow the position of the previous page')]
    public function pagesByPosition(): void
    {
        Directory::seed(self::createSchema());
        $repository = $this->ormRepository();
        $query = new ListQuery(sortBy: new AttributePath(self::USER, 'userName'), count: 2, attributes: [new AttributePath(self::USER, 'userName')]);

        $first = $repository->listAfter($query, null);
        $second = $repository->listAfter($query, $first?->next);

        self::assertSame([['alice', 'bjensen'], 4, true], [array_column($first->resources ?? [], 'userName'), $first?->totalResults, null !== $first?->next]);
        self::assertSame([['jsmith', 'omalley'], 4, null], [array_column($second->resources ?? [], 'userName'), $second?->totalResults, $second?->next]);
    }

    #[Test]
    #[TestDox('A filter that the plan makes constant false gives an empty page without a store call')]
    public function constantFalsePlan(): void
    {
        self::createSchema();
        $type = self::kernelMappings()->get('User')->resourceType;
        $filter = new FilterResolver()->resolve(new FilterParser()->parse('id eq "not-a-uuid"'), $type);

        self::assertEquals(new CursorPage([], 0, null), $this->ormRepository()->listAfter(new ListQuery($filter), null));
    }

    #[Test]
    #[TestDox('A store without keyset pages gives null, so the server pages by offset')]
    public function storeWithoutKeyset(): void
    {
        $mappings = self::mappings(['User' => Account::class, 'Group' => Team::class]);
        $store = new ArrayObjectStore()->add($mappings->get('Group'), new Team('Staff'));
        $stores = new ServiceLocator([ArrayObjectStore::class => static fn (): ArrayObjectStore => $store]);
        $mapper = self::mapper($mappings, $store);
        $repository = new ObjectResourceRepository('Group', $mappings, new QueryPlanner($mappings, self::converters()), $mapper, new Instantiator(new ServiceLocator([]), $mapper), $stores, self::converters());

        self::assertNull($repository->listAfter(new ListQuery(), null));
    }

    private function ormRepository(): ObjectResourceRepository
    {
        $container = self::getContainer();

        return new ObjectResourceRepository(
            'User',
            self::kernelMappings(),
            $container->get('tacticmedia_scim_server.query_planner'),
            $container->get('tacticmedia_scim_server.document_mapper'),
            $container->get('tacticmedia_scim_server.instantiator'),
            $container->get('tacticmedia_scim_server.store_locator'),
            $container->get('tacticmedia_scim_server.converter_locator'),
        );
    }
}
