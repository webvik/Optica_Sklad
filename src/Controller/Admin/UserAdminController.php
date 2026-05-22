<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\Admin\UserCreateFormType;
use App\Repository\UserRepository;
use App\Security\WarehouseRole;
use App\Service\Admin\UserCredentialsWhatsAppHandoff;
use App\Util\WhatsAppPhoneDigits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Length;

#[Route('/sprava/uzivatele', name: 'app_admin_users_')]
#[IsGranted(WarehouseRole::APP_ADMIN)]
final class UserAdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'users' => $users->createQueryBuilder('u')
                ->orderBy('u.username', 'ASC')
                ->getQuery()
                ->getResult(),
        ]);
    }

    #[Route('/novy', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        UserCredentialsWhatsAppHandoff $handoff,
    ): Response {
        $form = $this->createForm(UserCreateFormType::class, [
            'plainPassword' => UserCreateFormType::DEFAULT_PLAIN_PASSWORD,
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/user/new.html.twig', ['form' => $form]);
        }

        $level = (string) $form->get('accessLevel')->getData();
        if (!\in_array($level, WarehouseRole::assignableRoles(), true)) {
            $this->addFlash('error', 'Neplatná úroveň oprávnění.');

            return $this->render('admin/user/new.html.twig', ['form' => $form]);
        }

        $username = mb_strtolower(\trim((string) $form->get('username')->getData()), 'UTF-8');
        if (null !== $userRepository->findOneBy(['username' => $username])) {
            $this->addFlash('error', 'Účet s tímto přihlašovacím jménem už existuje.');

            return $this->render('admin/user/new.html.twig', ['form' => $form]);
        }

        $emailRaw = \trim((string) $form->get('email')->getData());
        $emailNormalized = '' !== $emailRaw ? $emailRaw : null;
        if (null !== $emailNormalized && null !== $userRepository->findOneBy(['email' => $emailNormalized])) {
            $this->addFlash('error', 'Účet s tímto e-mailem už existuje.');

            return $this->render('admin/user/new.html.twig', ['form' => $form]);
        }

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($emailNormalized);
        $fn = \trim((string) $form->get('firstName')->getData());
        $ln = \trim((string) $form->get('lastName')->getData());
        $user->setFirstName('' !== $fn ? $fn : null);
        $user->setLastName('' !== $ln ? $ln : null);
        $phoneTrim = \trim((string) $form->get('phone')->getData());
        $phoneNorm = '' !== $phoneTrim ? mb_substr($phoneTrim, 0, 32, 'UTF-8') : '';
        $user->setPhone('' !== $phoneNorm ? $phoneNorm : null);
        $user->setRoles([$level]);
        $user->setIsActive(true);
        /** @var string $plainPwd */
        $plainPwd = (string) $form->get('plainPassword')->getData();
        $user->setPassword($passwordHasher->hashPassword($user, $plainPwd));

        $em->persist($user);
        $em->flush();

        $this->addFlash('success', sprintf('Účet %s byl založen.', $username));

        $offer = true === $form->get('offerWhatsAppHandoff')->getData();
        if ($offer && '' !== $phoneNorm) {
            $digits = WhatsAppPhoneDigits::normalize($phoneNorm);
            if (\is_string($digits)) {
                try {
                    $waToken = $handoff->store($username, $plainPwd, $digits);

                    return $this->redirectToRoute('app_admin_users_after_create', ['token' => $waToken]);
                } catch (\Throwable) {
                    $this->addFlash(
                        'error',
                        'Účet byl založen, ale nepodařilo se připravit odkaz WhatsApp — zašlete heslo uživateli jiným způsobem.',
                    );
                }
            }
        }

        return $this->redirectToRoute('app_admin_users_index');
    }

    #[Route('/po-zalozeni', name: 'after_create', methods: ['GET'])]
    public function afterCreateWhatsApp(Request $request, UserCredentialsWhatsAppHandoff $handoff): Response
    {
        $token = (string) $request->query->get('token', '');
        if ('' === $token || !$handoff->isReady($token)) {
            $this->addFlash('error', 'Odkaz pro WhatsApp byl už použitý, vypršel nebo je neplatný.');

            return $this->redirectToRoute('app_admin_users_index');
        }

        return $this->render('admin/user/after_create_whatsapp.html.twig', [
            'whatsappHandoffUrl' => $this->generateUrl('app_admin_users_whatsapp_redirect', ['token' => $token]),
        ]);
    }

    #[Route('/odeslat-povereni-whatsapp/{token}', name: 'whatsapp_redirect', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function redirectWhatsAppHandoff(string $token, Request $request, UserCredentialsWhatsAppHandoff $handoff): Response
    {
        try {
            $data = $handoff->consume($token, delete: true);
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'Odkaz pro WhatsApp byl už použitý nebo vypršel.');

            return $this->redirectToRoute('app_admin_users_index');
        }

        $appDomain = $request->getSchemeAndHttpHost();
        $textLines = [
            'Přístupové údaje do aplikace Optický sklad:',
            'Login: '.$data['username'],
            'Heslo: '.$data['plainPassword'],
            'Adresa aplikace: '.$appDomain,
            '',
            'Po prvním přihlášení si změňte heslo.',
        ];

        return $this->redirect($handoff->buildWaUrl($data['waDigits'], implode("\n", $textLines)));
    }

    #[Route('/{id}/upravit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        Request $request,
        User $user,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $hadAppAdmin = \in_array(WarehouseRole::APP_ADMIN, $user->getAssignedRoles(), true);

        $builder = $this->createFormBuilder($user);
        $builder
            ->add('isActive', CheckboxType::class, [
                'label' => 'Účet aktivní',
                'required' => false,
            ])
            ->add('phone', TextType::class, [
                'label' => 'Telefon (volitelně)',
                'required' => false,
                'constraints' => [new Length(max: 32)],
                'help' => 'Pro kontakt v administraci (např. WhatsApp číslo jako +420 …).',
            ])
            ->add('newPasswordPlain', RepeatedType::class, [
                'mapped' => false,
                'type' => PasswordType::class,
                'required' => false,
                'trim' => true,
                'first_options' => [
                    'label' => 'Nové heslo (volitelně)',
                    'required' => false,
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Nové heslo znovu',
                    'required' => false,
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'Hesla musejí být shodná.',
                'help' => 'Nechte prázdné, pokud neměnit heslo. Vyplněním uživateli nastavíte nové přihlašovací heslo (např. po zapomenutí).',
            ]);

        $builder->get('newPasswordPlain')->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $plain = $event->getForm()->getData();
            if (!\is_string($plain)) {
                return;
            }

            $t = trim($plain);
            if ('' === $t) {
                return;
            }

            $len = \strlen($t);
            if ($len < 8) {
                $event->getForm()->addError(new FormError('Heslo alespoň 8 znaků.'));

                return;
            }
            if ($len > 4096) {
                $event->getForm()->addError(new FormError('Heslo je příliš dlouhé.'));
            }
        });

        $form = $builder
            ->add('accessLevel', ChoiceType::class, [
                'mapped' => false,
                'label' => 'Úroveň oprávnění',
                'choices' => WarehouseRole::formChoicesOrdered(),
                'expanded' => true,
                'data' => WarehouseRole::primaryFromAssignedRoles($user->getAssignedRoles()),
                'attr' => ['class' => 'spool-form__expanded-choice'],
            ])
            ->add('save', SubmitType::class, ['label' => 'Uložit'])
            ->getForm();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $level = (string) $form->get('accessLevel')->getData();

            if (!\in_array($level, WarehouseRole::assignableRoles(), true)) {
                $this->addFlash('error', 'Neplatná úroveň oprávnění.');

                return $this->redirectToRoute('app_admin_users_edit', ['id' => $user->getId()]);
            }

            $willHaveAppAdmin = WarehouseRole::APP_ADMIN === $level;
            $adminsNow = $this->countStoredAppAdmins($userRepository);
            $removingLastAppAdmin = $hadAppAdmin && !$willHaveAppAdmin && $adminsNow <= 1;
            if ($removingLastAppAdmin) {
                $this->addFlash(
                    'error',
                    'Nelze odebrat jedinému účtu administrátora aplikace roli „Aplikační administrátor“.',
                );

                return $this->redirectToRoute('app_admin_users_edit', ['id' => $user->getId()]);
            }

            /** @var User|string|null $current */
            $current = $this->getUser();
            $deactivatingSelf = $current instanceof User
                && $current->getId() === $user->getId()
                && !$user->getIsActive();
            if ($hadAppAdmin && $deactivatingSelf && $adminsNow <= 1) {
                $this->addFlash('error', 'Poslední aktivní administrátor se nemůže sám deaktivovat.');

                return $this->redirectToRoute('app_admin_users_edit', ['id' => $user->getId()]);
            }

            $deactivatingLastActiveAdmin = $hadAppAdmin && !$user->getIsActive() && $adminsNow <= 1;
            if ($deactivatingLastActiveAdmin) {
                $this->addFlash('error', 'Nelze deaktivovat jediného držitele role aplikačního administrátora.');

                return $this->redirectToRoute('app_admin_users_edit', ['id' => $user->getId()]);
            }

            $user->setRoles([$level]);

            /** @var string|null $pwdRaw */
            $pwdRaw = $form->get('newPasswordPlain')->getData();
            $pwdTrimmed = (\is_string($pwdRaw)) ? trim($pwdRaw) : '';
            $passwordChanged = '' !== $pwdTrimmed;
            if ($passwordChanged) {
                $user->setPassword($passwordHasher->hashPassword($user, $pwdTrimmed));
            }

            $phoneClean = \trim((string) ($user->getPhone() ?? ''));
            $user->setPhone('' !== $phoneClean ? mb_substr($phoneClean, 0, 32, 'UTF-8') : null);

            $em->flush();

            $message = 'Údaje uživatele byly uloženy.';
            if ($passwordChanged) {
                $message .= ' Heslo bylo změněno.';
            }
            $this->addFlash('success', $message);

            return $this->redirectToRoute('app_admin_users_index');
        }

        return $this->render('admin/user/edit.html.twig', [
            'editUser' => $user,
            'form' => $form,
            'canDeleteUser' => null === $this->deleteBlockReason($user, $userRepository),
            'deleteUserBlockedReason' => $this->deleteBlockReason($user, $userRepository),
        ]);
    }

    #[Route('/{id}/smazat', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        Request $request,
        User $user,
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $token = (string) $request->request->get('_delete_token', '');
        if (!$this->isCsrfTokenValid('admin_user_delete', $token)) {
            $this->addFlash('error', 'Neplatný požadavek (CSRF). Obnovte stránku a zkuste znovu.');

            return $this->redirectToRoute('app_admin_users_edit', ['id' => $user->getId()]);
        }

        $blockReason = $this->deleteBlockReason($user, $userRepository);
        if (null !== $blockReason) {
            $this->addFlash('error', $blockReason);

            return $this->redirectToRoute('app_admin_users_edit', ['id' => $user->getId()]);
        }

        $username = $user->getUserIdentifier();
        $em->remove($user);
        $em->flush();

        $this->addFlash('success', sprintf('Účet %s byl smazán.', $username));

        return $this->redirectToRoute('app_admin_users_index');
    }

    /** Počet řádků v DB majících ROLE_APP_ADMIN v JSON „roles“. */
    private function countStoredAppAdmins(UserRepository $userRepository): int
    {
        $n = 0;
        $list = $userRepository->createQueryBuilder('u')
            ->getQuery()
            ->getResult();

        foreach ($list as $row) {
            if ($row instanceof User && \in_array(WarehouseRole::APP_ADMIN, $row->getAssignedRoles(), true)) {
                ++$n;
            }
        }

        return $n;
    }

    /** null = smazání povoleno; jinak česká hláška proč ne. */
    private function deleteBlockReason(User $target, UserRepository $userRepository): ?string
    {
        $current = $this->getUser();
        if ($current instanceof User && $current->getId() === $target->getId()) {
            return 'Vlastní účet nelze smazat.';
        }

        if (\in_array(WarehouseRole::APP_ADMIN, $target->getAssignedRoles(), true)
            && $this->countStoredAppAdmins($userRepository) <= 1) {
            return 'Nelze smazat jediného aplikačního administrátora.';
        }

        return null;
    }
}
