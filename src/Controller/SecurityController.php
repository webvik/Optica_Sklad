<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\Account\ChangePasswordFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SecurityController extends AbstractController
{
    /**
     * Старые закладки /login ведут на главную.
     */
    #[Route('/login', name: 'app_login')]
    public function legacyLogin(): Response
    {
        return $this->redirectToRoute('app_home', [], Response::HTTP_FOUND);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This should never be reached (logout handled by the firewall).');
    }

    #[Route('/ucet/zmena-hesla', name: 'app_account_change_password', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        $authUser = $this->getUser();
        if (!$authUser instanceof User) {
            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $newPassword */
            $newPassword = (string) $form->get('newPassword')->getData();
            $authUser->setPassword($passwordHasher->hashPassword($authUser, $newPassword));
            $em->flush();

            $this->addFlash('success', 'Heslo bylo změněno.');

            return $this->redirectToRoute('app_account_change_password');
        }

        return $this->render('security/change_password.html.twig', [
            'form' => $form,
        ]);
    }
}
