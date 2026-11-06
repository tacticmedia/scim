<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServerBundle\Object\ObjectResourceRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\PrinterRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\RepositoryDecorator;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\UserRepositoryDecorator;

final class TacticMediaScimServerBundleTest extends KernelTestCase
{
    use CreatesSchema;

    #[Test]
    #[TestDox('The container registers the bundle extension as tacticmedia_scim_server')]
    public function theContainerRegistersTheBundleExtension(): void
    {
        $this->assertCommandIsSuccessful(self::runCommand('debug:config', ['name' => 'tacticmedia_scim_server']));
    }

    #[Test]
    #[TestDox('#[AsDecorator] decorates one repository service, #[AsTagDecorator] every one, raw repositories included')]
    public function repositoryServicesAreDecorated(): void
    {
        self::assertSame([RepositoryDecorator::class, UserRepositoryDecorator::class, ObjectResourceRepository::class], $this->chain('user'));
        self::assertSame([RepositoryDecorator::class, ObjectResourceRepository::class], $this->chain('group'));
        self::assertSame([RepositoryDecorator::class, PrinterRepository::class], $this->chain('printer'));
    }

    #[Test]
    #[TestDox('The server publishes the configuration, every resource type and the schemas of the raw repository')]
    public function theServerPublishesTheWiring(): void
    {
        $config = $this->request('GET', '/ServiceProviderConfig');
        $types = $this->request('GET', '/ResourceTypes');
        $printer = $this->request('GET', '/Schemas/urn:acme:scim:schemas:extension:site:1.0:Printer');

        self::assertSame([200, 1000, true], [$config['filter']['maxResults'] ?? null, $config['bulk']['maxOperations'] ?? null, $config['changePassword']['supported'] ?? null]);
        self::assertSame([['type' => 'oauthbearertoken', 'name' => 'OAuth Bearer Token', 'description' => 'Authentication with a bearer token in the Authorization header.', 'specUri' => 'https://www.rfc-editor.org/rfc/rfc6750', 'primary' => true]], $config['authenticationSchemes'] ?? null);
        self::assertSame(['Badge', 'Group', 'User', 'Printer'], array_column($this->resources($types), 'name'));
        self::assertSame(['PrinterSite', ['room']], [$printer['name'] ?? null, array_column(\is_array($printer['attributes'] ?? null) ? $printer['attributes'] : [], 'name')]);
    }

    #[Test]
    #[TestDox('A second group with the same displayName in any case is 409 uniqueness (Entra)')]
    public function duplicateGroupNamesConflict(): void
    {
        self::createSchema();
        $this->request('POST', '/Groups', ['displayName' => 'Staff']);
        $conflict = $this->request('POST', '/Groups', ['displayName' => 'STAFF']);

        self::assertSame(['409', 'uniqueness'], [$conflict['status'] ?? null, $conflict['scimType'] ?? null]);
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

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $server = self::getContainer()->get('test.tacticmedia_scim_server.server');
        $request = Request::create('/scim/v2'.$path, $method, server: ['CONTENT_TYPE' => 'application/scim+json'], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $decoded = json_decode((string) $server->handle($request, '/scim/v2')->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $list
     *
     * @return list<array<mixed>>
     */
    private function resources(array $list): array
    {
        self::assertIsList($list['Resources'] ?? null);

        return array_values(array_filter($list['Resources'], \is_array(...)));
    }
}
