<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use TacticMedia\ScimServer\Event\ResourceCreated;
use TacticMedia\ScimServer\Event\ResourceDeleted;
use TacticMedia\ScimServer\Event\ResourceUpdated;

#[AsEventListener]
final class ProvisioningRecorder
{
    /** @var list<ResourceCreated|ResourceUpdated|ResourceDeleted> */
    public array $events = [];

    public function __invoke(ResourceCreated|ResourceUpdated|ResourceDeleted $event): void
    {
        $this->events[] = $event;
    }
}
