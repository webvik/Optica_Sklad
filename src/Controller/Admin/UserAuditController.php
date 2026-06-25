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
        $sort = $this->parseAuditSort($request);
        $total = $logs->countAll();
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, $request->query->getInt('page', 1));
        if ($page > $pageCount) {
            return $this->redirectToRoute('app_admin_audit_index', $this->auditRouteParams($pageCount, $sort));
        }

        $offset = ($page - 1) * self::PAGE_SIZE;

        return $this->render('admin/audit/index.html.twig', [
            'entries' => $logs->findPaged(self::PAGE_SIZE, $offset, $sort['field'], $sort['dir']),
            'page' => $page,
            'pageCount' => $pageCount,
            'total' => $total,
            'pageSize' => self::PAGE_SIZE,
            'sort' => $sort['field'],
            'dir' => $sort['dir'],
        ]);
    }

    /**
     * @return array{field: 'time'|'user', dir: 'asc'|'desc'}
     */
    private function parseAuditSort(Request $request): array
    {
        $field = 'user' === $request->query->getString('sort') ? 'user' : 'time';
        $dirRaw = strtolower($request->query->getString('dir'));
        $dir = 'asc' === $dirRaw ? 'asc' : 'desc';

        return ['field' => $field, 'dir' => $dir];
    }

    /**
     * @param array{field: 'time'|'user', dir: 'asc'|'desc'} $sort
     *
     * @return array<string, int|string>
     */
    private function auditRouteParams(int $page, array $sort): array
    {
        $params = ['page' => $page];
        if ('user' === $sort['field']) {
            $params['sort'] = 'user';
            $params['dir'] = $sort['dir'];
        }

        return $params;
    }
}
