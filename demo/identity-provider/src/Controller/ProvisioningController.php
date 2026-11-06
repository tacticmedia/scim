<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\ProvisioningFailed;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\UserImporter;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningLogEntryRepository;

final class ProvisioningController extends AbstractController
{
    use ProvisionsChanges;
    private const int LOG_ENTRIES = 200;

    public function __construct(
        private readonly Provisioner $provisioner,
        private readonly UserImporter $importer,
        private readonly ProvisioningLogEntryRepository $logEntryRepository,
    ) {
    }

    #[Route('/provision', name: 'provision_all', methods: ['POST'])]
    public function provisionAll(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyInvalidToken($request, 'provision');
        $failures = $this->provisioner->syncAll();
        $this->addFlash([] === $failures ? 'success' : 'error', \sprintf('Full sync finished with %d failures.', \count($failures)));

        foreach ($failures as $failure) {
            $this->addFlash('error', $failure);
        }

        return $this->redirectToRoute('provisioning_log');
    }

    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    public function import(Request $request): Response
    {
        $count = UserImporter::PAGE_SIZE;
        $users = null;
        $lastLogEntryId = $this->logEntryRepository->lastId();

        if ($request->isMethod('POST')) {
            $this->denyInvalidToken($request, 'import');
            $count = max(1, $request->getPayload()->getInt('count', UserImporter::PAGE_SIZE));
            $users = $this->importedUsers($count);
        }

        return $this->render('provisioning/import.html.twig', ['count' => $count, 'users' => $users, 'entries' => $this->logEntryRepository->after($lastLogEntryId)]);
    }

    #[Route('/log', name: 'provisioning_log', methods: ['GET'])]
    public function log(): Response
    {
        return $this->render('provisioning/log.html.twig', ['entries' => $this->logEntryRepository->newest(self::LOG_ENTRIES)]);
    }

    /**
     * @param int<1, max> $count
     *
     * @return list<array<mixed>>|null null when the import failed
     */
    private function importedUsers(int $count): ?array
    {
        try {
            $users = $this->importer->import($count);
            $this->addFlash('success', \sprintf('Imported %d users.', \count($users)));

            return $users;
        } catch (ProvisioningFailed $provisioningFailed) {
            $this->addFlash('error', 'Import failed: '.$provisioningFailed->getMessage());

            return null;
        }
    }
}
