<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:seed-dev-users', description: 'Seeds local development users for each role.')]
final class SeedDevUsersCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'redact-password-output',
            null,
            InputOption::VALUE_NONE,
            'Suppress raw DEV_BOOTSTRAP_PASSWORD in command output (recommended for long-lived container logs).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $password = (string) ($_ENV['DEV_BOOTSTRAP_PASSWORD'] ?? $_SERVER['DEV_BOOTSTRAP_PASSWORD'] ?? '');
        if ($password === '') {
            $output->writeln('<error>DEV_BOOTSTRAP_PASSWORD is required for local user seeding.</error>');

            return Command::FAILURE;
        }

        $seedMatrix = [
            'standard_user' => [Role::STANDARD_USER],
            'content_admin' => [Role::CONTENT_ADMIN],
            'credential_reviewer' => [Role::CREDENTIAL_REVIEWER],
            'analyst_user' => [Role::ANALYST],
            'system_admin' => [Role::SYSTEM_ADMIN],
        ];

        foreach ($seedMatrix as $username => $roles) {
            $existing = $this->users->findOneByUsername($username);
            if ($existing instanceof User) {
                $existing->setRoles($roles);
                $existing->setPasswordHash($this->passwordHasher->hashPassword($existing, $password));
                $existing->clearLockoutState();
                continue;
            }

            $user = new User($username);
            $user->setRoles($roles);
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
            $user->clearLockoutState();
            $this->entityManager->persist($user);
        }

        $this->entityManager->flush();

        if ($input->getOption('redact-password-output') === true) {
            $output->writeln('Seeded local users. Shared dev password output is redacted for this invocation.');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Seeded local users. Shared dev password: %s', $password));

        return Command::SUCCESS;
    }
}
