<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;
use TacticMedia\ScimDemo\IdentityProvider\Entity\ProvisioningLogEntry;

/**
 * @extends ServiceEntityRepository<ProvisioningLogEntry>
 */
final class ProvisioningLogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProvisioningLogEntry::class);
    }

    /**
     * @return list<ProvisioningLogEntry>
     */
    public function newest(int $limit): array
    {
        return $this->findBy([], ['id' => 'DESC'], $limit);
    }

    public function lastId(): int
    {
        return $this->findOneBy([], ['id' => 'DESC'])->id ?? 0;
    }

    /**
     * @return list<ProvisioningLogEntry> oldest first
     */
    public function after(int $id): array
    {
        return $this->matching(Criteria::create()->where(Criteria::expr()->gt('id', $id))->orderBy(['id' => Order::Ascending]))->getValues();
    }
}
