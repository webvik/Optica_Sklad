<?php

namespace App\Controller\Warehouse;

use App\Entity\Spool;
use App\Entity\SpoolEvent;
use App\Entity\User;
use App\Enum\SpoolEventType;
use App\Enum\SpoolStatus;
use App\Form\SpoolEventFormType;
use App\Form\SpoolFormType;
use App\Repository\SpoolRepository;
use App\Service\Warehouse\SpoolMeterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sklad/spool', name: 'warehouse_spool_')]
final class SpoolController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, SpoolRepository $repo): Response
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
        $oneSpoolJson = null;
        if (1 === \count($spools)) {
            $oneSpoolJson = \json_encode(
                $this->serializeSpoolLookup($spools[0]),
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE
            );
        }

        return $this->render('warehouse/spool/index.html.twig', [
            'searchQuery' => $q,
            'searchPerformed' => '' !== $q,
            'spools' => $spools,
            'oneSpoolJson' => $oneSpoolJson,
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
    public function lookup(Request $request, SpoolRepository $repo): JsonResponse
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
        $payload = array_map(fn (Spool $s) => $this->serializeSpoolLookup($s), $spools);

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

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, SpoolMeterService $meter): Response
    {
        $spool = new Spool();
        if (null === $spool->getRegisteredAt()) {
            $spool->setRegisteredAt(new \DateTimeImmutable('today'));
        }
        $form = $this->createForm(SpoolFormType::class, $spool);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $u = $this->getUser();
            if ($u instanceof User) {
                $spool->setCreatedBy($u);
                $spool->setUpdatedBy($u);
            }
            $meter->initNewSpoolState($spool);
            $em->persist($spool);
            $em->flush();
            $this->addFlash('success', 'Cívka byla zaevidována.');

            return $this->redirectToRoute('warehouse_spool_show', ['id' => $spool->getId()]);
        }

        return $this->render('warehouse/spool/form.html.twig', [
            'form' => $form,
            'title' => 'Nová cívka do evidence',
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function show(Request $request, Spool $spool, SpoolMeterService $meter, EntityManagerInterface $em): Response
    {
        $event = new SpoolEvent();
        $event->setSpool($spool);
        $form = $this->createForm(SpoolEventFormType::class, $event);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $u = $this->getUser() instanceof User ? $this->getUser() : null;
            $type = $event->getType();
            try {
                if (SpoolEventType::MeterReading === $type) {
                    if (null === $event->getVisibleM()) {
                        $this->addFlash('error', 'U odběru dle metru zadejte „viditelné čtení metru“ (m).');

                        return $this->render('warehouse/spool/show.html.twig', [
                            'spool' => $spool,
                            'form' => $form,
                        ]);
                    }
                    $meter->applyMeterReading(
                        $spool,
                        $event->getVisibleM(),
                        $event->getOccurredAt(),
                        $event->getProjectLabel(),
                        $u
                    );
                } else {
                    if (SpoolEventType::Transfer === $type && (null === $event->getNote() || '' === trim($event->getNote() ?? ''))) {
                        $this->addFlash('error', 'U předání uveďte komu / kam (pole „Poznámka“).');

                        return $this->render('warehouse/spool/show.html.twig', [
                            'spool' => $spool,
                            'form' => $form,
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
        ]);
    }

    private function serializeSpoolLookup(Spool $s): array
    {
        $evs = $s->getEvents()->toArray();
        usort($evs, static function (SpoolEvent $a, SpoolEvent $b): int {
            $t = $a->getOccurredAt() <=> $b->getOccurredAt();

            return 0 === $t ? ($a->getId() ?? 0) <=> ($b->getId() ?? 0) : $t;
        });
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
            'currentRemainingM' => $s->getCurrentRemainingM(),
            'initialVisibleM' => $s->getInitialVisibleM(),
            'lastVisibleM' => $s->getLastVisibleM(),
            'status' => $s->getStatus()->value,
            'statusLabel' => $this->spoolStatusLabel($s->getStatus()),
            'family' => $s->getFamily(),
            'lastEvents' => $eventsOut,
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
            SpoolEventType::MeterReading => 'odběr dle metru',
            SpoolEventType::Transfer => 'předání',
            SpoolEventType::Writeoff => 'vyřazení',
            SpoolEventType::Inventory => 'inventura',
            SpoolEventType::Correction => 'oprava',
            SpoolEventType::LaidSection => 'úsek (štítek)',
        };
    }
}
