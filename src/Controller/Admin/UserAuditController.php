<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\UserAuditLogRepository;
use App\Security\WarehouseRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sprava/protokol', name: 'app_admin_audit_')]
#[IsGranted(WarehouseRole::APP_ADMIN)]
final class UserAuditController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserAuditLogRepository $logs): Response
    {
        return $this->render('admin/audit/index.html.twig', [
            'entries' => $logs->findRecent(600),
        ]);
    }
}
