<?php

namespace App\Controller\Warehouse;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Příjem: zadání katalogu typů a nových cívek (odděleno od běžné práce s odběry).
 */
#[Route('/sklad/prijem', name: 'warehouse_receipt_')]
final class ReceiptController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('warehouse/receipt.html.twig');
    }
}
