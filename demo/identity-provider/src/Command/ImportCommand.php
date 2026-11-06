<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Json;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\ProvisioningFailed;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\UserImporter;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningLogEntryRepository;

#[AsCommand('idp:import', 'Reads every user of the service provider page by page, and prints each request and the users.')]
final readonly class ImportCommand
{
    public function __construct(
        private UserImporter $importer,
        private ProvisioningLogEntryRepository $logEntryRepository,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('The users per page')] int $count = UserImporter::PAGE_SIZE): int
    {
        $lastLogEntryId = $this->logEntryRepository->lastId();

        try {
            $result = $this->importer->import(max(1, $count));
        } catch (ProvisioningFailed $provisioningFailed) {
            $result = $provisioningFailed;
        }

        foreach ($this->logEntryRepository->after($lastLogEntryId) as $entry) {
            $io->writeln(\sprintf('%s %s %d', $entry->method, $entry->url, $entry->status));
        }

        if ($result instanceof ProvisioningFailed) {
            $io->error($result->getMessage());

            return Command::FAILURE;
        }

        $io->table(['id', 'userName', 'displayName', 'active'], array_map($this->row(...), $result));
        $io->success(\sprintf('Imported %d users.', \count($result)));

        return Command::SUCCESS;
    }

    /**
     * @param array<mixed> $user
     *
     * @return list<string>
     */
    private function row(array $user): array
    {
        $active = match ($user['active'] ?? null) {
            true => 'yes',
            false => 'no',
            default => '',
        };

        return [Json::string($user, 'id') ?? '', Json::string($user, 'userName') ?? '', Json::string($user, 'displayName') ?? '', $active];
    }
}
