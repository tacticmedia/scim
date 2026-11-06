<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;
use TacticMedia\ScimDemo\IdentityProvider\Form\UserType;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;

final class UserController extends AbstractController
{
    use ProvisionsChanges;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Provisioner $provisioner,
    ) {
    }

    #[Route('/', name: 'user_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('user/index.html.twig', ['users' => $this->entityManager->getRepository(DirectoryUser::class)->findBy([], ['id' => 'ASC'])]);
    }

    #[Route('/users/new', name: 'user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form($request, new DirectoryUser());
    }

    #[Route('/users/{id}/edit', name: 'user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DirectoryUser $user): Response
    {
        return $this->form($request, $user);
    }

    #[Route('/users/{id}/active', name: 'user_toggle_active', methods: ['POST'])]
    public function toggleActive(Request $request, DirectoryUser $user): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyInvalidToken($request, 'user-'.$user->id);
        $user->active = !$user->active;
        $this->save($user, \sprintf('User "%s" %s.', $user->userName, $user->active ? 'enabled' : 'disabled'));

        return $this->redirectToRoute('user_index');
    }

    #[Route('/users/{id}/delete', name: 'user_delete', methods: ['POST'])]
    public function delete(Request $request, DirectoryUser $user): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyInvalidToken($request, 'user-'.$user->id);
        $scimId = $user->scimId;

        if ($this->deprovisioned(\sprintf('User "%s"', $user->userName), null === $scimId ? null : fn () => $this->provisioner->deleteUser($scimId))) {
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('user_index');
    }

    private function form(Request $request, DirectoryUser $user): Response
    {
        $form = $this->createForm(UserType::class, $user)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->save($user, \sprintf('User "%s" saved.', $user->userName));

            return $this->redirectToRoute('user_index');
        }

        return $this->render('user/form.html.twig', ['form' => $form, 'user' => $user]);
    }

    private function save(DirectoryUser $user, string $savedMessage): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->provision(fn () => $this->provisioner->syncUser($user), $savedMessage);
    }
}
