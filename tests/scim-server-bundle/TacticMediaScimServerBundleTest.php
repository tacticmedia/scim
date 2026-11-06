<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServerBundle\Object\ObjectResourceRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\PrinterRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\RepositoryDecorator;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\UserRepositoryDecorator;

final class TacticMediaScimServerBundleTest extends KernelTestCase
{
    #[Test]
    #[TestDox('#[AsDecorator] decorates one repository service, #[AsTagDecorator] every one, raw repositories included')]
    public function repositoryServicesAreDecorated(): void
    {
        self::assertSame([RepositoryDecorator::class, UserRepositoryDecorator::class, ObjectResourceRepository::class], $this->chain('user'));
        self::assertSame([RepositoryDecorator::class, ObjectResourceRepository::class], $this->chain('group'));
        self::assertSame([RepositoryDecorator::class, PrinterRepository::class], $this->chain('printer'));
    }

    /**
     * @return list<class-string>
     */
    private function chain(string $name): array
    {
        $chain = [];

        for ($repository = self::getContainer()->get('test.tacticmedia_scim_server.repository.'.$name); $repository instanceof RepositoryDecorator || $repository instanceof UserRepositoryDecorator; $repository = $repository->inner) {
            $chain[] = $repository::class;
        }

        return [...$chain, $repository::class];
    }
}
