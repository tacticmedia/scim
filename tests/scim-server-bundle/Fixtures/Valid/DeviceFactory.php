<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Valid;

use TacticMedia\ScimServerBundle\Object\ObjectFactory;

final readonly class DeviceFactory implements ObjectFactory
{
    public function create(array $document): Device
    {
        return new Device(\is_string($document['serialNumber'] ?? null) ? $document['serialNumber'] : '');
    }
}
