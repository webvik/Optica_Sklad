<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\UserAuditLogRepository;
use App\Security\WarehouseRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sprava/protokol', name: 'app_admin_audit_')]
#[IsGranted(WarehouseRole::APP_ADMIN)]
final class UserAuditController extends AbstractController
{
    private const PAGE_SIZE = 250;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, UserAuditLogRepository $logs): Response
    {
        $total = $logs->countAll();
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, $request->query->getInt('page', 1));
        if ($page > $pageCount) {
            return $this->redirectToRoute('app_admin_audit_index', ['page' => $pageCount]);
        }

        $offset = ($page - 1) * self::PAGE_SIZE;

        return $this->render('admin/audit/index.html.twig', [
            'entries' => $logs->findRecent(self::PAGE_SIZE, $offset),
            'page' => $page,
            'pageCount' => $pageCount,
            'total' => $total,
            'pageSize' => self::PAGE_SIZE,
        ]);
    }
}
