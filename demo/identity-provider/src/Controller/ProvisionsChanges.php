<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\ProvisioningFailed;

/**
 * @phpstan-require-extends AbstractController
 */
trait ProvisionsChanges
{
    /**
     * Runs after the local change is saved. A failure keeps the local change.
     */
    private function provision(\Closure $provisioning, string $savedMessage): void
    {
        try {
            $provisioning();
            $this->addFlash('success', $savedMessage.' Provisioned.');
        } catch (ProvisioningFailed $provisioningFailed) {
            $this->addFlash('error', $savedMessage.' Provisioning failed: '.$provisioningFailed->getMessage());
        }
    }

    /**
     * Runs before the local delete. A failure keeps the entry and its SCIM id, so the next delete retries.
     *
     * @param \Closure|null $deprovisioning null when the entry was not provisioned
     */
    private function deprovisioned(string $label, ?\Closure $deprovisioning): bool
    {
        try {
            $deprovisioning?->__invoke();
        } catch (ProvisioningFailed $provisioningFailed) {
            $this->addFlash('error', $label.' not deleted. Provisioning failed: '.$provisioningFailed->getMessage());

            return false;
        }

        $this->addFlash('success', $label.' deleted. '.($deprovisioning instanceof \Closure ? 'Provisioned.' : 'It was not provisioned.'));

        return true;
    }

    private function denyInvalidToken(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('The CSRF token is not valid.');
        }
    }
}
