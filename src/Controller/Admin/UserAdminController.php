<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\WarehouseRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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

    #[Route('/{id}/upravit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        Request $request,
        User $user,
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $hadAppAdmin = \in_array(WarehouseRole::APP_ADMIN, $user->getAssignedRoles(), true);

        $form = $this->createFormBuilder($user)
            ->add('isActive', CheckboxType::class, [
                'label' => 'Účet aktivní',
                'required' => false,
            ])
            ->add('accessLevel', ChoiceType::class, [
                'mapped' => false,
                'label' => 'Úroveň oprávnění',
                'choices' => WarehouseRole::formChoicesOrdered(),
                'expanded' => true,
                'data' => WarehouseRole::primaryFromAssignedRoles($user->getAssignedRoles()),
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
            $adminsNow = $userRepository->countWithAssignedRole(WarehouseRole::APP_ADMIN);
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
            $em->flush();
            $this->addFlash('success', 'Údaje uživatele byly uloženy.');

            return $this->redirectToRoute('app_admin_users_index');
        }

        return $this->render('admin/user/edit.html.twig', [
            'editUser' => $user,
            'form' => $form,
        ]);
    }
}
