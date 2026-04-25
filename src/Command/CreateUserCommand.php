<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Vytvořit uživatele: přihlášení = username, heslo; volitelně e-mail, jméno, příjmení',
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = mb_strtolower(trim((string) $input->getArgument('username')), 'UTF-8');
        $plain = (string) $input->getArgument('password');
        $email = $input->getArgument('email');
        $firstName = $input->getArgument('firstName');
        $lastName = $input->getArgument('lastName');

        $user = new User();
        $user->setUsername($username);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plain));
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
            'Uživatel <info>%s</info> (id <info>%d</info>) byl vytvořen.',
            $username,
            $user->getId() ?? 0
        ));

        return Command::SUCCESS;
    }
}
