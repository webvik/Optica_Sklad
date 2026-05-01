<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\WarehouseRole;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Vytvořit uživatele: přihlášení + heslo; volitelně e-mail, jméno; přepínač --role výchozí EDIT',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Přihlašovací jméno, např. ivan.novak');
        $this->addArgument('password', InputArgument::REQUIRED, 'Heslo');
        $this->addArgument('email', InputArgument::OPTIONAL, 'E-mail (volitelné)');
        $this->addArgument('firstName', InputArgument::OPTIONAL, 'Jméno');
        $this->addArgument('lastName', InputArgument::OPTIONAL, 'Příjmení');
        $this->addOption(
            'role',
            null,
            InputOption::VALUE_REQUIRED,
            'Přímá přiřazená role: ' . implode(', ', WarehouseRole::assignableRoles()),
            WarehouseRole::EDIT,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = mb_strtolower(trim((string) $input->getArgument('username')), 'UTF-8');
        $plain = (string) $input->getArgument('password');
        $email = $input->getArgument('email');
        $firstName = $input->getArgument('firstName');
        $lastName = $input->getArgument('lastName');
        $role = (string) $input->getOption('role');

        if (!\in_array($role, WarehouseRole::assignableRoles(), true)) {
            $output->writeln('<error>Neplatná --role.</error>');

            return Command::FAILURE;
        }

        $user = new User();
        $user->setUsername($username);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plain));
        $user->setRoles([$role]);
        if (null !== $email && '' !== trim($email)) {
            $user->setEmail(trim($email));
        }
        if (null !== $firstName && '' !== trim($firstName)) {
            $user->setFirstName(trim($firstName));
        }
        if (null !== $lastName && '' !== trim($lastName)) {
            $user->setLastName(trim($lastName));
        }

        $this->users->save($user);

        $output->writeln(sprintf(
            'Uživatel <info>%s</info> (id <info>%d</info>), role <info>%s</info>.',
            $username,
            $user->getId() ?? 0,
            $role,
        ));

        return Command::SUCCESS;
    }
}
