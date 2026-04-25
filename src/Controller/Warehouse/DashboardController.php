<?php

namespace App\Controller\Warehouse;

use App\Repository\CableTypeRepository;
use App\Repository\SpoolRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sklad', name: 'warehouse_')]
final class DashboardController extends AbstractController
{
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(CableTypeRepository $cableTypes, SpoolRepository $spools): Response
    {
        return $this->render('warehouse/dashboard.html.twig', [
            'cableTypeCount' => $cableTypes->count([]),
            'spoolCount' => $spools->count([]),
        ]);
    }
}
