<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use TacticMedia\ScimDemo\IdentityProvider\Form\SettingsType;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningSettingsRepository;

final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProvisioningSettingsRepository $settingsRepository,
    ) {
    }

    #[Route('/settings', name: 'settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $settings = $this->settingsRepository->current();
        $form = $this->createForm(SettingsType::class, $settings)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($settings);
            $this->entityManager->flush();
            $this->addFlash('success', 'Settings saved.');

            return $this->redirectToRoute('settings');
        }

        return $this->render('settings.html.twig', ['form' => $form]);
    }
}
