<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * Six users in a manager chain, the group "Engineering" and the group "Platform Team", whose members are a subset of
 * the members of "Engineering".
 */
#[AsCommand('idp:seed', 'Creates a small sample directory in an empty directory.')]
final readonly class SeedCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (null !== $this->entityManager->getRepository(DirectoryUser::class)->findOneBy([])) {
            $io->error('The directory is not empty.');

            return Command::FAILURE;
        }

        $ceo = $this->user('E001', 'Ada', 'Lovelace', 'Executive', null);
        $cto = $this->user('E002', 'Grace', 'Hopper', 'Engineering', $ceo);
        $lead = $this->user('E003', 'Linus', 'Torvalds', 'Engineering', $cto);
        $developer = $this->user('E004', 'Margaret', 'Hamilton', 'Engineering', $lead);
        $tester = $this->user('E005', 'Dennis', 'Ritchie', 'Engineering', $lead);
        $this->user('E006', 'Mary', 'Jackson', 'Sales', $ceo);
        $this->group('Engineering', $cto, $lead, $developer, $tester);
        $this->group('Platform Team', $lead, $developer);
        $this->entityManager->flush();
        $io->success('Created 6 users and 2 groups.');

        return Command::SUCCESS;
    }

    private function user(string $employeeNumber, string $givenName, string $familyName, string $department, ?DirectoryUser $manager): DirectoryUser
    {
        $user = new DirectoryUser();
        $user->userName = strtolower($givenName.'.'.$familyName).'@example.com';
        $user->givenName = $givenName;
        $user->familyName = $familyName;
        $user->displayName = $givenName.' '.$familyName;
        $user->workEmail = $user->userName;
        $user->department = $department;
        $user->employeeNumber = $employeeNumber;
        $user->manager = $manager;
        $user->demoPlainTextPassword = 'Demo-'.$familyName.'-1';

        $this->entityManager->persist($user);

        return $user;
    }

    private function group(string $displayName, DirectoryUser ...$members): void
    {
        $group = new DirectoryGroup();
        $group->displayName = $displayName;

        foreach ($members as $member) {
            $group->members->add($member);
        }

        $this->entityManager->persist($group);
    }
}
