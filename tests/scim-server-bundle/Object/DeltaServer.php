<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Event\ResourcePatched;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServerBundle\Object\ObjectResourceRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\RepositoryDecorator;

/**
 * For KernelTestCase tests: a library server over the repositories of the test kernel. The Group repository of the
 * kernel is a RepositoryDecorator, which hides DeltaRepository, so it takes the full PATCH path.
 */
trait DeltaServer
{
    /** @var list<class-string> */
    private array $events = [];

    private static function deltaGroups(): ObjectResourceRepository
    {
        $inner = self::decoratedGroups()->inner;
        self::assertInstanceOf(ObjectResourceRepository::class, $inner);

        return $inner;
    }

    private static function decoratedGroups(): RepositoryDecorator
    {
        return self::getContainer()->get('test.tacticmedia_scim_server.repository.group');
    }

    private function serverOf(ResourceRepository $groups): ScimServer
    {
        $mappings = self::getContainer()->get('test.tacticmedia_scim_server.mapping_registry');
        $users = self::getContainer()->get('test.tacticmedia_scim_server.repository.user');
        $dispatcher = new EventDispatcher();

        foreach ([ResourceUpdated::class, ResourcePatched::class] as $event) {
            $dispatcher->addListener($event, function (object $event): void {
                $this->events[] = $event::class;
            });
        }

        return new ScimServer([
            new RegisteredResourceType($mappings->get('User')->resourceType, $users),
            new RegisteredResourceType($mappings->get('Group')->resourceType, $groups),
        ], eventDispatcher: $dispatcher);
    }

    /**
     * @param array<array-key, mixed>|null $body    sent as JSON
     * @param array<string, string>        $headers
     */
    private static function request(ScimServer $server, string $method, string $uri, ?array $body = null, array $headers = []): Response
    {
        $request = Request::create('http://localhost/scim/v2'.$uri, $method, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $request->headers->add(['Content-Type' => 'application/scim+json', ...$headers]);

        return $server->handle($request, '/scim/v2');
    }
}
