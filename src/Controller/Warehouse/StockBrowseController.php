<?php

namespace App\Controller\Warehouse;

use App\Enum\SpoolStatus;
use App\Repository\CableTypeRepository;
use App\Repository\SpoolRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prohlídka skladu: filtry, přehled zásob (k inventuře / kontrole).
 */
#[Route('/sklad/pohled', name: 'warehouse_browse_')]
final class StockBrowseController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        SpoolRepository $spools,
        CableTypeRepository $cableTypes,
    ): Response {
        $cableTypeId = $request->query->get('cableTypeId');
        $cableTypeId = is_numeric($cableTypeId) ? (int) $cableTypeId : null;
        if ($cableTypeId <= 0) {
            $cableTypeId = null;
        }

        $statusParam = $request->query->get('status');
        $status = \is_string($statusParam) ? SpoolStatus::tryFrom($statusParam) : null;

        return $this->render('warehouse/stock_browse.html.twig', [
            'spools' => $spools->findFiltered($cableTypeId, $status),
            'cableTypeChoices' => $cableTypes->findBy([], ['name' => 'ASC']),
            'filterCableTypeId' => $cableTypeId,
            'filterStatus' => $status,
        ]);
    }
}
