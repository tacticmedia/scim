<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Fixtures\Enum;

enum UserType: string
{
    case Employee = 'Employee';
    case Contractor = 'Contractor';
    case Intern = 'Intern';
}
