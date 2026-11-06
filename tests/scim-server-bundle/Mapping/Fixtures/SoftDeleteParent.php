<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures;

use TacticMedia\ScimServerBundle\Mapping\ScimSoftDelete;

abstract class SoftDeleteParent
{
    #[ScimSoftDelete]
    private ?\DateTimeInterface $deletedAt = null;

    public function deletedAt(): ?\DateTimeInterface
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeInterface $deletedAt): void
    {
        $this->deletedAt = $deletedAt;
    }
}
