<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Form\GroupType;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;

final class GroupController extends AbstractController
{
    use ProvisionsChanges;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Provisioner $provisioner,
    ) {
    }

    #[Route('/groups', name: 'group_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('group/index.html.twig', ['groups' => $this->entityManager->getRepository(DirectoryGroup::class)->findBy([], ['id' => 'ASC'])]);
    }

    #[Route('/groups/new', name: 'group_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form($request, new DirectoryGroup());
    }

    #[Route('/groups/{id}/edit', name: 'group_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DirectoryGroup $group): Response
    {
        return $this->form($request, $group);
    }

    #[Route('/groups/{id}/delete', name: 'group_delete', methods: ['POST'])]
    public function delete(Request $request, DirectoryGroup $group): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyInvalidToken($request, 'group-'.$group->id);
        $scimId = $group->scimId;

        if ($this->deprovisioned(\sprintf('Group "%s"', $group->displayName), null === $scimId ? null : fn () => $this->provisioner->deleteGroup($scimId))) {
            $this->entityManager->remove($group);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('group_index');
    }

    private function form(Request $request, DirectoryGroup $group): Response
    {
        $form = $this->createForm(GroupType::class, $group)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($group);
            $this->entityManager->flush();
            $this->provision(fn () => $this->provisioner->syncGroup($group), \sprintf('Group "%s" saved.', $group->displayName));

            return $this->redirectToRoute('group_index');
        }

        return $this->render('group/form.html.twig', ['form' => $form, 'group' => $group]);
    }
}
