<?php

namespace App\Controller\Warehouse;

use App\Entity\CableFamily;
use App\Entity\CableType;
use App\Entity\Spool;
use App\Entity\SpoolEvent;
use App\Entity\User;
use App\Enum\SpoolEventType;
use App\Enum\SpoolStatus;
use App\Form\SpoolAssignCableTypeFormType;
use App\Form\SpoolEventFormType;
use App\Form\SpoolFormType;
use App\Repository\CableFamilyRepository;
use App\Repository\SpoolRepository;
use App\Service\Warehouse\CableTypeOcrMatcher;
use App\Service\Warehouse\SpoolEventOrder;
use App\Service\Warehouse\SpoolMeterService;
use App\Security\WarehouseRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sklad/spool', name: 'warehouse_spool_')]
final class SpoolController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, SpoolRepository $repo, SpoolMeterService $meter): Response
    {
        $q = \trim((string) $request->query->get('q', ''));
        $spools = [];
        if ('' !== $q) {
            try {
                $spools = $repo->searchByReelInput($q, 25);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Chyba vyhledávání: '.$e->getMessage());
            }
        }
        $spoolRemaining = [];
        $spoolsLookupJson = null;
        if ($spools !== []) {
            foreach ($spools as $s) {
                $spoolRemaining[$s->getId()] = $meter->remainingForTableDisplay($s);
            }
            $spoolsLookupJson = \json_encode(
                \array_map(fn (Spool $s) => $this->serializeSpoolLookup($s, $meter), $spools),
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE
            );
        }

        return $this->render('warehouse/spool/index.html.twig', [
            'searchQuery' => $q,
            'searchPerformed' => '' !== $q,
            'spools' => $spools,
            'spoolRemaining' => $spoolRemaining,
            'spoolsLookupJson' => $spoolsLookupJson,
            'work_can_register_spool' => $this->isGranted(WarehouseRole::EDIT),
            /** Diagnostika skenu kamery na mobilu — přidej ?scanDbg=1 na URL „Práce s optikou“. */
            'work_scan_client_log' => $request->query->getBoolean('scanDbg'),
        ]);
    }

    /** Telemetrie z klienta (BarcodeDetector je jen v prohlížeči). Bez raw hodnot čárového kódu kvůli citlivosti údajů. */
    #[Route('/client-scan-log', name: 'client_scan_log', methods: ['POST'])]
    public function clientScanLog(Request $request, LoggerInterface $logger): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['ok' => false], 400);
        }
        $csrf = (string) ($payload['csrf'] ?? '');
        unset($payload['csrf']);
        if (!$this->isCsrfTokenValid('work_scan_client', $csrf)) {
            return new JsonResponse(['ok' => false, 'error' => 'csrf'], 403);
        }
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, ['event', 'sareMode', 'codesCount', 'fmtTier', 'pickedLen', 'ui'], true)) {
                unset($payload[$key]);
            }
        }
        $user = $this->getUser();
        $logger->info('[work-scan-client]', [
            'event' => $payload['event'] ?? null,
            'sareMode' => $payload['sareMode'] ?? null,
            'codesCount' => $payload['codesCount'] ?? null,
            'fmtTier' => $payload['fmtTier'] ?? null,
            'pickedLen' => $payload['pickedLen'] ?? null,
            'ui' => $payload['ui'] ?? null,
            'ip' => $request->getClientIp(),
            'user' => $user instanceof User ? $user->getUserIdentifier() : null,
        ]);

        return new JsonResponse(['ok' => true]);
    }

    /** OCR štítek typu → nejlepší shoda z katalogu nebo prázdno (bez uložení těla v logech). */
    #[Route('/cable-type-ocr-match', name: 'cable_type_ocr_match', methods: ['POST'])]
    public function cableTypeOcrMatch(Request $request, CableTypeOcrMatcher $matcher): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['ok' => false], 400);
        }
        $csrf = (string) ($payload['csrf'] ?? '');
        if (!$this->isCsrfTokenValid('spool_cable_type_ocr_match', $csrf)) {
            return new JsonResponse(['ok' => false, 'error' => 'csrf'], 403);
        }
        $text = trim((string) ($payload['text'] ?? ''));
        if (\mb_strlen($text) < 2) {
            return new JsonResponse(['ok' => false, 'error' => 'text'], 400);
        }

        $r = $matcher->matchWithCandidates($text, 14);
        $hints = isset($r['hints']) && \is_array($r['hints']) ? $r['hints'] : [];
        $candidatesOut = [];
        foreach (($r['candidates'] ?? []) as $row) {
            $ctRow = \is_array($row) ? ($row['cableType'] ?? null) : null;
            if (!$ctRow instanceof CableType) {
                continue;
            }
            $candidatesOut[] = [
                'score' => \round((float) ($row['score'] ?? 0.0), 1),
                'cableType' => $this->cableTypeToOcrMatchArray($ctRow),
            ];
        }

        $bt = $r['cableType'];
        if ($r['matched'] && $bt instanceof CableType) {
            return new JsonResponse([
                'ok' => true,
                'matched' => true,
                'score' => round($r['score'], 1),
                'margin' => round($r['margin'], 2),
                'hints' => $hints,
                'candidates' => $candidatesOut,
                'cableType' => $this->cableTypeToOcrMatchArray($bt),
            ]);
        }

        return new JsonResponse([
            'ok' => true,
            'matched' => false,
            'score' => round($r['score'], 1),
            'margin' => round($r['margin'], 2),
            'normalizedQuery' => \mb_substr((string) $r['normalizedQuery'], 0, 480),
            'hints' => $hints,
            'candidates' => $candidatesOut,
        ]);
    }

    /** Úplný seznam cívek (dříve na úvodní stránce sekce). */
    #[Route('/seznam', name: 'list', methods: ['GET'])]
    public function listAll(SpoolRepository $repo): Response
    {
        return $this->render('warehouse/spool/list.html.twig', [
            'spools' => $repo->findBy([], ['reelNumber' => 'ASC'], 500),
        ]);
    }

    /** Náhled cívek podle čísla saře (AJAX). */
    #[Route('/lookup', name: 'lookup', methods: ['GET'])]
    public function lookup(Request $request, SpoolRepository $repo, SpoolMeterService $meter): JsonResponse
    {
        $q = (string) $request->query->get('q', '');
        $qTrim = trim($q);
        $digitRun = \preg_replace('/\D+/', '', $qTrim) ?? '';
        try {
            $spools = $repo->searchByReelInput($q, 25);
        } catch (\Throwable $e) {
            return $this->json(
                [
                    'ok' => false,
                    'error' => 'search_exception',
                    'message' => $e->getMessage(),
                    'query' => $qTrim,
                    'count' => 0,
                    'spools' => [],
                    'search' => [
                        'field' => 'reelNumber',
                        'digitRun' => $digitRun,
                        'exception' => $e::class,
                    ],
                ],
                500
            );
        }
        $payload = array_map(fn (Spool $s) => $this->serializeSpoolLookup($s, $meter), $spools);

        return $this->json([
            'ok' => true,
            'query' => $qTrim,
            'count' => \count($payload),
            'spools' => $payload,
            'search' => [
                'field' => 'reelNumber',
                'digitRun' => $digitRun,
                'digitCount' => \strlen($digitRun),
                'orDigitSubstring' => \strlen($digitRun) >= 3,
            ],
        ]);
    }

    /** Přesná existence čísla saře — pro kontrolu duplicity před zápisem nové cívky. Vyžaduje právo zápisů. */
    #[Route('/check-reel', name: 'check_reel', methods: ['GET'])]
    #[IsGranted(WarehouseRole::EDIT)]
    public function checkReelExists(Request $request, SpoolRepository $repo): JsonResponse
    {
        $q = \trim((string) $request->query->get('q', ''));
        if ('' === $q) {
            return $this->json(['ok' => false, 'error' => 'empty'], 400);
        }
        if (\strlen($q) > 127) {
            return $this->json(['ok' => false, 'error' => 'too_long'], 400);
        }
        $spool = $repo->findOneByReelNumberExactIgnoreCase($q);
        if (null === $spool) {
            return $this->json([
                'ok' => true,
                'exists' => false,
                'normalizedQuery' => $q,
            ]);
        }

        return $this->json([
            'ok' => true,
            'exists' => true,
            'normalizedQuery' => $q,
            'id' => $spool->getId(),
            'reelNumber' => $spool->getReelNumber(),
            'showUrl' => $this->generateUrl('warehouse_spool_show', ['id' => (int) $spool->getId()]),
        ]);
    }

    /** Zápis zafuku (metr) ze stránky „Práce s optikou“ (Běžný stav + zakázka). */
    #[Route('/zaznam-prace', name: 'work_record', methods: ['POST'])]
    public function workRecord(Request $request, SpoolRepository $repo, SpoolMeterService $meter, EntityManagerInterface $em): Response
    {
        $returnQ = \trim((string) $request->request->get('return_q', ''));
        if (!$this->isCsrfTokenValid('spool_work_record', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Neplatný token. Zkuste znovu.');

            return $this->redirectToRoute('warehouse_spool_index', ['q' => $returnQ]);
        }
        $id = (int) $request->request->get('spool_id', 0);
        $spool = $id > 0 ? $repo->findOneWithEventsById($id) : null;
        if (null === $spool) {
            $this->addFlash('error', 'Cívka nenalezena.');

            return $this->redirectToRoute('warehouse_spool_index', ['q' => $returnQ]);
        }
        $rawM = $request->request->get('visible_m');
        if (null === $rawM || '' === (string) $rawM) {
            $this->addFlash('error', 'Zadejte „Běžný stav (aktuální metráž)“ v metrech.');

            return $this->redirectToRoute('warehouse_spool_index', ['q' => $spool->getReelNumber()]);
        }
        $visibleInt = (int) $rawM;
        $project = (string) $request->request->get('project', '');
        $u = $this->getUser() instanceof User ? $this->getUser() : null;
        try {
            $meter->applyMeterReading(
                $spool,
                $visibleInt,
                new \DateTimeImmutable('now'),
                '' !== $project ? $project : null,
                $u
            );
            if ($u instanceof User) {
                $spool->setUpdatedBy($u);
            }
            $em->flush();
            $this->addFlash('success', 'Zafuk byl zapsán do deníku.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        // Vždy přesné číslo saře (ne částečný dotaz v poli hledání) → jedna shoda, Záznam + karta.
        return $this->redirectToRoute('warehouse_spool_index', ['q' => $spool->getReelNumber()]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted(WarehouseRole::EDIT)]
    public function new(Request $request, EntityManagerInterface $em, SpoolMeterService $meter, CableFamilyRepository $cableFamilyRepository): Response
    {
        $spool = new Spool();
        if (null === $spool->getRegisteredAt()) {
            $spool->setRegisteredAt(new \DateTimeImmutable('today'));
        }

        $reelPrefill = \trim((string) $request->query->get('reel', ''));
        if ('' !== $reelPrefill && !$request->isMethod(Request::METHOD_POST)) {
            $spool->setReelNumber($reelPrefill);
        }

        $form = $this->createForm(SpoolFormType::class, $spool);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (null === $spool->getCableType()) {
                $filterFamily = $form->get('cableFamilyFilter')->getData();
                $spool->setFamily($filterFamily instanceof CableFamily ? $filterFamily->getCode() : '');
            }
            $u = $this->getUser();
            if ($u instanceof User) {
                $spool->setCreatedBy($u);
                $spool->setUpdatedBy($u);
            }
            $meter->initNewSpoolState($spool);
            $em->persist($spool);
            $em->flush();
            $msg = 'Cívka byla zaevidována.';
            if (null === $spool->getCableType()) {
                $msg .= ' Typ kabelu můžete kdykoli doplnit na kartě cívky (Doplnit typ kabelu).';
            }
            $this->addFlash('success_modal', $msg);

            return $this->redirectToRoute('warehouse_spool_show', ['id' => $spool->getId()]);
        }

        $familyLabels = [];
        foreach ($cableFamilyRepository->findForPicker() as $f) {
            $familyLabels[$f->getCode()] = $f->getLabel();
        }

        return $this->render('warehouse/spool/form.html.twig', [
            'form' => $form,
            'title' => 'Nová cívka do evidence',
            'cable_family_labels_json' => \json_encode($familyLabels, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            'spool_reel_check_lookup_url' => $this->generateUrl('warehouse_spool_check_reel'),
        ]);
    }

    /** HTML fragment karty cívky (shrnutí + deník) pro stránku „Práce s optikou“ (AJAX). */
    #[Route('/{id}/karta-embed', name: 'karta_embed', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function kartaEmbed(int $id, SpoolRepository $repo): Response
    {
        $spool = $repo->findOneWithEventsById($id);
        if (null === $spool) {
            throw $this->createNotFoundException();
        }

        return $this->render('warehouse/spool/_karta_work_embed.html.twig', [
            'spool' => $spool,
        ]);
    }

    /**
     * Doplnění typu kabelu u cívky založené bez typu (POST z karty cívky).
     */
    #[Route('/{id}/doplnit-typ', name: 'assign_cable', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assignCableType(Request $request, Spool $spool, EntityManagerInterface $em): Response
    {
        if (null !== $spool->getCableType()) {
            $this->addFlash('warning', 'Typ kabelu je u této cívky již uveden.');

            return $this->redirectToRoute('warehouse_spool_show', ['id' => $spool->getId()]);
        }
        $form = $this->createForm(SpoolAssignCableTypeFormType::class, $spool);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $u = $this->getUser();
            if ($u instanceof User) {
                $spool->setUpdatedBy($u);
            }
            $em->flush();
            $this->addFlash('success', 'Typ kabelu byl uložen.');

            return $this->redirectToRoute('warehouse_spool_show', ['id' => $spool->getId()]);
        }
        $this->addFlash('error', 'Typ kabelu se nepodařilo uložit. Zkontrolujte výběr.');

        return $this->redirectToRoute('warehouse_spool_show', ['id' => $spool->getId()]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function show(Request $request, Spool $spool, SpoolMeterService $meter, EntityManagerInterface $em): Response
    {
        $assignCableForm = null;
        if (null === $spool->getCableType()) {
            $assignCableForm = $this->createForm(SpoolAssignCableTypeFormType::class, $spool)->createView();
        }
        $event = new SpoolEvent();
        $event->setSpool($spool);
        $form = $this->createForm(SpoolEventFormType::class, $event, [
            'spool' => $spool,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $u = $this->getUser() instanceof User ? $this->getUser() : null;
            // Date-only pole → occurred_at 00:00:00, ve stejném dni se pak seřadí PŘED dřívější záznamy
            // s reálným časem; nový by „zmizel“ dole pod tabulkou. Doplníme čas odeslání.
            if (null !== $event->getOccurredAt()) {
                $now = new \DateTimeImmutable();
                $event->setOccurredAt(
                    $event->getOccurredAt()->setTime(
                        (int) $now->format('H'),
                        (int) $now->format('i'),
                        (int) $now->format('s')
                    )
                );
            }
            $type = $event->getType();
            try {
                if (SpoolEventType::MeterReading === $type) {
                    if (null === $event->getVisibleM()) {
                        $this->addFlash('error', 'U zafuku zadejte „viditelné čtení metru“ (m).');

                        return $this->render('warehouse/spool/show.html.twig', [
                            'spool' => $spool,
                            'form' => $form,
                            'assignCableForm' => $assignCableForm,
                        ]);
                    }
                    $meter->applyMeterReading(
                        $spool,
                        $event->getVisibleM(),
                        $event->getOccurredAt(),
                        $event->getProjectLabel(),
                        $u
                    );
                } elseif (SpoolEventType::LaidSection === $type && null !== $event->getVisibleM()) {
                    $meter->applyVisibleChainEvent(
                        $spool,
                        SpoolEventType::LaidSection,
                        $event->getVisibleM(),
                        $event->getOccurredAt(),
                        $event->getProjectLabel(),
                        $event->getNote(),
                        $u
                    );
                } elseif (SpoolEventType::Writeoff === $type) {
                    $event->setVisibleM(null);
                    $r = (int) $form->get('writeoffRemainderM')->getData();
                    $bookBefore = $spool->getCurrentRemainingM() ?? $spool->getTotalLengthM();
                    $meter->recordWriteoff(
                        $spool,
                        $event->getOccurredAt(),
                        $r,
                        $event->getNote(),
                        $u
                    );
                    if ($r < $bookBefore) {
                        $this->addFlash('warning', 'Fyzický zbytek ('.$r.' m) je menší než zůstatek v evidenci před zápisem ('.$bookBefore.' m) — může se projevit varování u součtu délka / zůstatek / odběry. Ověřte a případě proveďte korekci.');
                    }
                } else {
                    if (SpoolEventType::Transfer === $type && (null === $event->getNote() || '' === trim($event->getNote() ?? ''))) {
                        $this->addFlash('error', 'U předání uveďte komu / kam (pole „Poznámka“).');

                        return $this->render('warehouse/spool/show.html.twig', [
                            'spool' => $spool,
                            'form' => $form,
                            'assignCableForm' => $assignCableForm,
                        ]);
                    }
                    $meter->recordNonMeterEvent(
                        $spool,
                        $type,
                        $event->getOccurredAt(),
                        $event->getVisibleM(),
                        $event->getProjectLabel(),
                        $event->getNote(),
                        $u
                    );
                }
                if ($u instanceof User) {
                    $spool->setUpdatedBy($u);
                }
                $em->flush();
                $em->refresh($spool);
                $this->addFlash('success', 'Událost byla zapsána.');

                return $this->redirectToRoute('warehouse_spool_show', ['id' => $spool->getId()]);
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('warehouse/spool/show.html.twig', [
            'spool' => $spool,
            'form' => $form,
            'assignCableForm' => $assignCableForm,
        ]);
    }

    private function serializeSpoolLookup(Spool $s, SpoolMeterService $meter): array
    {
        $rem = $meter->remainingForTableDisplay($s);
        $evs = SpoolEventOrder::byVisibleM($s->getMeterSign(), $s->getEvents()->toArray());
        $tail = \array_slice($evs, -5);
        $tail = array_reverse($tail);

        $eventsOut = [];
        foreach ($tail as $e) {
            $eventsOut[] = [
                'occurredAt' => $e->getOccurredAt()?->format(\DateTimeInterface::ATOM),
                'type' => $e->getType()->value,
                'typeLabel' => $this->eventTypeLabel($e->getType()),
                'visibleM' => $e->getVisibleM(),
                'usedMeters' => $e->getUsedMeters(),
                'projectLabel' => $e->getProjectLabel(),
            ];
        }

        return [
            'id' => $s->getId(),
            'reelNumber' => $s->getReelNumber(),
            'cableTypeCode' => $s->getCableType()?->getCode(),
            'cableTypeName' => $s->getCableType()?->getName(),
            'totalLengthM' => $s->getTotalLengthM(),
            'currentRemainingM' => $rem['remaining'],
            'initialVisibleM' => $s->getInitialVisibleM(),
            'lastVisibleM' => $s->getLastVisibleM(),
            'previewPrevVisibleM' => $meter->previewPrevVisibleForMeterStep($s),
            'fiberCount' => $s->getEffectiveFiberCount(),
            'remainingDirectionOk' => $rem['directionOk'],
            'remainingWarning' => $rem['warning'],
            'meterNumberingLabel' => $rem['directionLabel'],
            'status' => $s->getStatus()->value,
            'statusLabel' => $this->spoolStatusLabel($s->getStatus()),
            'family' => $s->getFamily(),
            'lastEvents' => $eventsOut,
        ];
    }

    private function cableTypeToOcrMatchArray(CableType $c): array
    {
        $d = $c->getDiameterMm();

        return [
            'id' => $c->getId(),
            'code' => $c->getCode(),
            'name' => $c->getName(),
            'family' => $c->getFamily(),
            'fiberCount' => $c->getFiberCount(),
            'diameterMm' => (null !== $d && '' !== (string) $d) ? (string) $d : null,
            'constructionCode' => $c->getConstructionCode(),
            'fullDescription' => $c->getFullDescription(),
        ];
    }

    private function spoolStatusLabel(SpoolStatus $s): string
    {
        return match ($s) {
            SpoolStatus::InStock => 'na skladě',
            SpoolStatus::Transferred => 'předáno',
            SpoolStatus::WrittenOff => 'vyřazeno',
        };
    }

    private function eventTypeLabel(SpoolEventType $t): string
    {
        return match ($t) {
            SpoolEventType::MeterReading => 'zafuk',
            SpoolEventType::Transfer => 'předání',
            SpoolEventType::Writeoff => 'vyřazení',
            SpoolEventType::Inventory => 'inventura',
            SpoolEventType::Correction => 'korekce',
            SpoolEventType::LaidSection => 'úsek (štítek)',
        };
    }
}
