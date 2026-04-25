<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
}
