<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\ServiceProvider\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use TacticMedia\ScimDemo\ServiceProvider\Entity\Group;
use TacticMedia\ScimDemo\ServiceProvider\Entity\User;

final class DirectoryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/', name: 'directory', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('directory.html.twig', [
            'users' => $this->entityManager->getRepository(User::class)->findBy(['deletedAt' => null], ['userName' => 'ASC']),
            'groups' => $this->entityManager->getRepository(Group::class)->findBy([], ['displayName' => 'ASC']),
        ]);
    }
}
