<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object\Fixtures;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

/**
 * Hides the User whose userName is the parameter "userName", as a soft-delete filter hides a deleted row.
 */
final class HiddenUserFilter extends SQLFilter
{
    #[\Override]
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return User::class === $targetEntity->getName() ? \sprintf('%s.%s <> %s', $targetTableAlias, $targetEntity->getColumnName('userName'), $this->getParameter('userName')) : '';
    }
}
