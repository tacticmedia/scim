<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\UserAddress;

/**
 * The element collection User "addresses" and the DQL expression of User "name.formatted" through HTTP.
 */
final class ElementCollectionTest extends WebTestCase
{
    use ScimClient;

    #[Test]
    #[TestDox('POST stores each address as a row, and GET renders them in id order with name.formatted')]
    public function createsElements(): void
    {
        $created = $this->send('POST', '/scim/v2/Users', [
            'schemas' => [Scim::USER],
            'userName' => 'bjensen',
            'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen', 'formatted' => 'ignored'],
            'addresses' => [['type' => 'work', 'streetAddress' => '100 Main St', 'locality' => 'Paris', 'primary' => true], ['type' => 'home', 'locality' => 'Rome', 'region' => 'unmapped']],
        ]);

        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        self::assertSame(
            [['givenName' => 'Barbara', 'familyName' => 'Jensen', 'formatted' => 'Barbara Jensen'], [['streetAddress' => '100 Main St', 'locality' => 'Paris', 'type' => 'work', 'primary' => true], ['locality' => 'Rome', 'type' => 'home', 'primary' => false]]],
            [self::json($created)['name'] ?? null, self::json($created)['addresses'] ?? null],
        );
        self::assertSame(self::json($created), self::json($this->send('GET', '/scim/v2/Users/'.self::idOf($created))));
        self::assertCount(2, $this->entityManager()->getRepository(UserAddress::class)->findAll());
    }

    #[Test]
    #[TestDox('PATCH updates the matching address in place, and PUT removes the addresses it omits')]
    public function updatesAndRemovesElements(): void
    {
        $id = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen', 'addresses' => [['type' => 'work', 'locality' => 'Paris'], ['type' => 'home', 'locality' => 'Rome']]]));
        $rows = $this->rows();

        self::assertNoContent($this->send('PATCH', '/scim/v2/Users/'.$id, self::patchOp(['op' => 'replace', 'path' => 'addresses[type eq "home"].locality', 'value' => 'Milan'])));

        self::assertSame([['locality' => 'Paris', 'type' => 'work', 'primary' => false], ['locality' => 'Milan', 'type' => 'home', 'primary' => false]], self::json($this->send('GET', '/scim/v2/Users/'.$id))['addresses'] ?? null);
        self::assertSame($rows, $this->rows());

        $replaced = $this->send('PUT', '/scim/v2/Users/'.$id, ['schemas' => [Scim::USER], 'userName' => 'bjensen', 'addresses' => [['type' => 'Work', 'locality' => 'Lyon', 'primary' => true]]]);

        self::assertSame([['locality' => 'Lyon', 'type' => 'Work', 'primary' => true]], self::json($replaced)['addresses'] ?? null);
        self::assertSame([$rows[0]], $this->rows());
    }

    #[Test]
    #[TestDox('Filters and sortBy use the addresses and name.formatted')]
    public function filtersAndSorts(): void
    {
        $paris = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen', 'name' => ['givenName' => 'Barbara'], 'addresses' => [['type' => 'home', 'locality' => 'Berlin'], ['type' => 'work', 'locality' => 'Paris', 'primary' => true]]]));
        $lyon = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'jsmith', 'name' => ['familyName' => 'Smith'], 'addresses' => [['type' => 'work', 'locality' => 'Lyon']]]));

        self::assertSame([$paris], self::ids($this->send('GET', '/scim/v2/Users?filter='.rawurlencode('addresses[type eq "work" and locality eq "paris"]'))));
        self::assertSame([], self::ids($this->send('GET', '/scim/v2/Users?filter='.rawurlencode('addresses[type eq "home" and locality eq "paris"]'))));
        self::assertSame([$lyon], self::ids($this->send('GET', '/scim/v2/Users?filter='.rawurlencode('name.formatted ew "smith"'))));
        self::assertSame([$lyon, $paris], self::ids($this->send('GET', '/scim/v2/Users?sortBy=addresses.locality')));
        self::assertSame([$paris, $lyon], self::ids($this->send('GET', '/scim/v2/Users?sortBy=name.formatted')));
    }

    #[Test]
    #[TestDox('A response without addresses does not load them')]
    public function excludedAddressesStayUnloaded(): void
    {
        $id = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen', 'addresses' => [['type' => 'work', 'locality' => 'Paris']]]));
        $this->entityManager()->clear();

        $response = $this->send('GET', '/scim/v2/Users?excludedAttributes=addresses');
        $addresses = $this->entityManager()->find(User::class, Uuid::fromString($id))?->getAddresses();

        self::assertArrayNotHasKey('addresses', self::resources($response)[0]);
        self::assertInstanceOf(PersistentCollection::class, $addresses);
        self::assertFalse($addresses->isInitialized());
    }

    /**
     * @return list<int|null> the ids of the address rows
     */
    private function rows(): array
    {
        $this->entityManager()->clear();

        return array_map(static fn (UserAddress $address): ?int => $address->id, $this->entityManager()->getRepository(UserAddress::class)->findBy([], ['id' => 'ASC']));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
