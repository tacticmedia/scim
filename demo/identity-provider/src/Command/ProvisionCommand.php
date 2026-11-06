<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningLogEntryRepository;

#[AsCommand('idp:provision', 'Provisions every user, then every group, and prints each request.')]
final readonly class ProvisionCommand
{
    public function __construct(
        private Provisioner $provisioner,
        private ProvisioningLogEntryRepository $logEntryRepository,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $lastLogEntryId = $this->logEntryRepository->lastId();
        $failures = $this->provisioner->syncAll();

        foreach ($this->logEntryRepository->after($lastLogEntryId) as $entry) {
            $io->writeln(\sprintf('%s %s %d', $entry->method, $entry->url, $entry->status));
        }

        if ([] !== $failures) {
            $io->error($failures);

            return Command::FAILURE;
        }

        $io->success('Every user and group is provisioned.');

        return Command::SUCCESS;
    }
}
