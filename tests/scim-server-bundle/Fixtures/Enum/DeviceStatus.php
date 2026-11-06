<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Enum;

enum DeviceStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
}
