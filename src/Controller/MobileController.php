<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MobileController extends AbstractController
{
    #[Route('/mobil', name: 'app_mobile_info', methods: ['GET'])]
    public function info(): Response
    {
        return $this->render('mobile/android.html.twig');
    }
}

