<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

final class DoctrineFixturesTest extends KernelTestCase
{
    use CreatesSchema;

    #[Test]
    #[TestDox('The fixture entities form a valid Doctrine mapping')]
    public function fixtureEntitiesAreValid(): void
    {
        $this->assertCommandIsSuccessful(self::runCommand('doctrine:schema:validate', ['--skip-sync' => true]));
    }

    #[Test]
    #[TestDox('createSchema() gives a database that stores users, groups and nested groups')]
    public function createSchemaStoresTheFixtures(): void
    {
        $entityManager = self::createSchema();
        $user = new User('bjensen');
        $parent = new Group('Staff');
        $child = new Group('Sales');
        $parent->addUser($user);
        $parent->addSubgroup($child);

        $entityManager->persist($user);
        $entityManager->persist($parent);
        $entityManager->persist($child);
        $entityManager->flush();
        $entityManager->clear();

        $stored = $entityManager->find(Group::class, $parent->getId());

        self::assertSame(['bjensen'], $stored?->getUsers()->map(static fn (User $member): string => $member->getUserName())->getValues());
        self::assertSame(['Sales'], $stored->getSubgroups()->map(static fn (Group $member): string => $member->getName())->getValues());
    }
}
