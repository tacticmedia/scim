<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Doctrine\Orm\Mapping\ScimDqlExpression;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UserBasics;

#[ScimResource('User')]
final class DqlExpressionOnComplex
{
    use UserBasics;

    /**
     * @return list<array{value: string}>
     */
    #[ScimAttribute('emails')]
    #[ScimDqlExpression('{alias}.email')]
    public function emails(): array
    {
        return [];
    }
}
