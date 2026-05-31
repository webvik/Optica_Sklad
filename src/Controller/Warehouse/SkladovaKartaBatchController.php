<?php

declare(strict_types=1);

namespace App\Controller\Warehouse;

use App\Entity\User;
use App\Repository\SpoolRepository;
use App\Security\WarehouseRole;
use App\Service\Warehouse\SkladovaKartaBatchShareTokenService;
use App\Service\Warehouse\SkladovaKartaPdfExporter;
use App\Service\Warehouse\SkladovaKartaPrintQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sklad/spool/skladove-karty-k-tisku', name: 'warehouse_spool_karty_')]
final class SkladovaKartaBatchController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(SpoolRepository $spools, SkladovaKartaPrintQueue $queue): Response
    {
        $queueSpools = $spools->findByIdsOrdered($queue->ids());
        $unprintedTotal = $spools->countWithoutWarehouseCard();
        $queueIdSet = array_flip($queue->ids());
        $unprintedNotInQueue = 0;
        foreach ($spools->findIdsWithoutWarehouseCard(500) as $id) {
            if (!isset($queueIdSet[$id])) {
                ++$unprintedNotInQueue;
            }
        }

        return $this->render('warehouse/spool/karty_k_tisku.html.twig', [
            'queueSpools' => $queueSpools,
            'unprintedTotal' => $unprintedTotal,
            'unprintedNotInQueue' => $unprintedNotInQueue,
            'defaultMessage' => 'Prosím vytiskni přiložené skladové karty (duplex: liché strany, pak sudé).',
        ]);
    }

    #[Route('/pdf', name: 'pdf', methods: ['POST'])]
    public function pdf(
        Request $request,
        SpoolRepository $spools,
        SkladovaKartaPdfExporter $exporter,
    ): Response {
        if (!$this->isCsrfTokenValid('skladova_karta_batch_pdf', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný formulář.');
        }

        $selected = self::parseSpoolIds($request);
        $batch = $this->loadBatchOrRedirect($spools, $selected);
        if ($batch instanceof Response) {
            return $batch;
        }

        try {
            $result = $exporter->downloadBatch($batch);
            if ($result['truncated']) {
                $this->addFlash('warning', 'U některých karet byl deník zkrácen (více než 39 záznamů).');
            }

            return $result['response'];
        } catch (\Throwable $e) {
            return new Response(
                'Chyba PDF: '.$e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }
    }

    #[Route('/odkaz', name: 'share_link', methods: ['POST'])]
    public function shareLink(
        Request $request,
        SpoolRepository $spools,
        SkladovaKartaBatchShareTokenService $tokens,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('skladova_karta_batch_share_link', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Neplatný formulář.'], 403);
        }

        $selected = self::parseSpoolIds($request);
        if ($selected === []) {
            return $this->json(['ok' => false, 'message' => 'Vyberte alespoň jednu cívku.'], 400);
        }

        $batch = $spools->findByIdsOrdered($selected);
        if (\count($batch) !== \count($selected)) {
            return $this->json(['ok' => false, 'message' => 'Některé vybrané cívky nebyly nalezeny.'], 404);
        }

        return $this->json([
            'ok' => true,
            'shareUrl' => $tokens->downloadUrl($batch),
        ]);
    }

    #[Route('/sklad/spool/skladove-karty-sdilet/{token}.pdf', name: 'share_pdf', methods: ['GET'])]
    public function sharePdf(
        string $token,
        SkladovaKartaBatchShareTokenService $tokens,
        SpoolRepository $spools,
        SkladovaKartaPdfExporter $exporter,
    ): Response {
        $ids = $tokens->parseValidIds($token);
        if (null === $ids) {
            throw $this->createNotFoundException('Odkaz ke stažení skladových karet není platný nebo vypršel.');
        }

        $batch = $spools->findByIdsOrdered($ids);
        if (\count($batch) !== \count($ids)) {
            throw $this->createNotFoundException('Odkaz ke stažení skladových karet není platný nebo vypršel.');
        }

        try {
            $result = $exporter->downloadBatch($batch);

            return $result['response'];
        } catch (\Throwable $e) {
            return new Response(
                'Chyba PDF: '.$e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }
    }

    #[Route('/oznacit', name: 'mark_printed', methods: ['POST'])]
    #[IsGranted(WarehouseRole::EDIT)]
    public function markPrinted(
        Request $request,
        SpoolRepository $spools,
        SkladovaKartaPrintQueue $queue,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('skladova_karta_batch_mark', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný formulář.');
        }

        $selected = self::parseSpoolIds($request);
        if ($selected === []) {
            $this->addFlash('error', 'Vyberte alespoň jednu cívku.');

            return $this->redirectToRoute('warehouse_spool_karty_index');
        }

        $now = new \DateTimeImmutable();
        $u = $this->getUser();
        $batch = $spools->findByIdsOrdered($selected);
        foreach ($batch as $spool) {
            $spool->setWarehouseCardPrintedAt($now);
            if ($u instanceof User) {
                $spool->setUpdatedBy($u);
            }
        }
        $em->flush();
        $queue->removeMany($selected);

        $this->addFlash('success', \count($batch) === 1
            ? 'Skladová karta označena jako vytištěná.'
            : 'Skladové karty označeny jako vytištěné ('.\count($batch).').');

        return $this->redirectToRoute('warehouse_spool_karty_index');
    }

    #[Route('/odebrat', name: 'remove', methods: ['POST'])]
    #[IsGranted(WarehouseRole::EDIT)]
    public function removeFromQueue(Request $request, SkladovaKartaPrintQueue $queue): Response
    {
        if (!$this->isCsrfTokenValid('skladova_karta_batch_remove', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný formulář.');
        }

        $id = (int) $request->request->get('spoolId');
        if ($id > 0) {
            $queue->remove($id);
            $this->addFlash('success', 'Cívka odebrána ze seznamu k tisku.');
        }

        return $this->redirectToRoute('warehouse_spool_karty_index');
    }

    #[Route('/pridat-bez-karty', name: 'add_unprinted', methods: ['POST'])]
    #[IsGranted(WarehouseRole::EDIT)]
    public function addUnprinted(Request $request, SpoolRepository $spools, SkladovaKartaPrintQueue $queue): Response
    {
        if (!$this->isCsrfTokenValid('skladova_karta_batch_add_unprinted', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný formulář.');
        }

        $queue->enqueueMany($spools->findIdsWithoutWarehouseCard(500));
        $this->addFlash('success', 'Do seznamu k tisku byly přidány všechny cívky bez vytištěné karty.');

        return $this->redirectToRoute('warehouse_spool_karty_index');
    }

    #[Route('/vycistit', name: 'clear', methods: ['POST'])]
    #[IsGranted(WarehouseRole::EDIT)]
    public function clearQueue(Request $request, SkladovaKartaPrintQueue $queue): Response
    {
        if (!$this->isCsrfTokenValid('skladova_karta_batch_clear', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný formulář.');
        }

        $queue->clear();
        $this->addFlash('success', 'Seznam k tisku byl vyprázdněn.');

        return $this->redirectToRoute('warehouse_spool_karty_index');
    }

    /** @return list<int> */
    private static function parseSpoolIds(Request $request): array
    {
        $raw = $request->request->all('spoolIds');
        if (!\is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }

        return array_values($ids);
    }

    /**
     * @param list<int> $selected
     *
     * @return list<\App\Entity\Spool>|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    private function loadBatchOrRedirect(SpoolRepository $spools, array $selected): array|Response
    {
        if ($selected === []) {
            $this->addFlash('error', 'Vyberte alespoň jednu cívku.');

            return $this->redirectToRoute('warehouse_spool_karty_index');
        }

        $batch = $spools->findByIdsOrdered($selected);
        if (\count($batch) !== \count($selected)) {
            $this->addFlash('error', 'Některé vybrané cívky nebyly nalezeny.');

            return $this->redirectToRoute('warehouse_spool_karty_index');
        }

        return $batch;
    }
}
