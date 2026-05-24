<?php

namespace App\Controller\Warehouse;

use App\Enum\SpoolStatus;
use App\Service\Warehouse\CableTypeBrowseFilter;
use App\Service\Warehouse\InventuraBriefGroupLabel;
use App\Service\Warehouse\InventuraExcelExporter;
use App\Service\Warehouse\SpoolMeterService;
use App\Repository\CableTypeRepository;
use App\Repository\SpoolEventRepository;
use App\Repository\SpoolRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        $choiceEntities = $cableTypes->findAllOrderedForCableTypePicker(false);
        $allCableTypeIds = array_values(array_map(
            static fn ($ct) => (int) $ct->getId(),
            $choiceEntities,
        ));
        \sort($allCableTypeIds, \SORT_NUMERIC);

        $cableTypeIdsFromForm = self::parseIdList($request, 'cableTypeIds');
        if ($cableTypeIdsFromForm === []) {
            $legacy = $request->query->get('cableTypeId');
            if (is_numeric($legacy) && (int) $legacy > 0) {
                $cableTypeIdsFromForm = [(int) $legacy];
            }
        }
        $cableTypeUnsetFromForm = self::parseCableTypeUnset($request);
        $cableTypeFilter = self::resolveCableTypeBrowseFilter(
            $cableTypeIdsFromForm,
            $cableTypeUnsetFromForm,
            $allCableTypeIds,
        );

        $statuses = self::parseStatusList($request);
        $onlyNeedsCorrection = self::parseNeedsCorrectionFilter($request);
        $reelQ = \trim((string) $request->query->get('q', ''));

        return $this->render('warehouse/stock_browse.html.twig', [
            'spools' => $spools->findFiltered(
                $cableTypeFilter,
                $statuses,
                '' !== $reelQ ? $reelQ : null,
                500,
                $onlyNeedsCorrection,
            ),
            'searchQuery' => $reelQ,
            'cableTypeChoices' => $choiceEntities,
            'filterCableTypeIds' => $cableTypeIdsFromForm,
            'filterCableTypeUnset' => $cableTypeUnsetFromForm,
            'cableTypeFilterIsAll' => !$cableTypeFilter->restrictsCableDimension()
                && ($cableTypeIdsFromForm !== [] || $cableTypeUnsetFromForm),
            'cableTypeFilterIsNone' => $cableTypeIdsFromForm === [] && !$cableTypeUnsetFromForm
                && !$cableTypeFilter->restrictsCableDimension(),
            'allStatusesSelected' => \count($statuses) === \count(SpoolStatus::cases()),
            'filterStatusValues' => array_map(
                static fn (SpoolStatus $s) => $s->value,
                $statuses,
            ),
            'filterNeedsCorrection' => $onlyNeedsCorrection,
        ]);
    }

    /** Živé hledání čísla saře v rámci aktuálních filtrů přehledu (AJAX). */
    #[Route('/api/hledat-sare', name: 'reel_search', methods: ['GET'])]
    public function reelSearch(
        Request $request,
        SpoolRepository $spools,
        CableTypeRepository $cableTypes,
    ): JsonResponse {
        $q = \trim((string) $request->query->get('q', ''));
        if ('' === $q) {
            return $this->json([
                'ok' => true,
                'query' => '',
                'count' => 0,
                'spoolIds' => [],
            ]);
        }

        $choiceEntities = $cableTypes->findAllOrderedForCableTypePicker(false);
        $allCableTypeIds = array_values(array_map(
            static fn ($ct) => (int) $ct->getId(),
            $choiceEntities,
        ));
        \sort($allCableTypeIds, \SORT_NUMERIC);

        $cableTypeIdsFromForm = self::parseIdList($request, 'cableTypeIds');
        $cableTypeFilter = self::resolveCableTypeBrowseFilter(
            $cableTypeIdsFromForm,
            self::parseCableTypeUnset($request),
            $allCableTypeIds,
        );
        $statuses = self::parseStatusList($request);
        $onlyNeedsCorrection = self::parseNeedsCorrectionFilter($request);

        try {
            $ids = $spools->searchIdsByReelWithinFilters($q, $cableTypeFilter, $statuses, 500, $onlyNeedsCorrection);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'query' => $q,
                'count' => 0,
                'spoolIds' => [],
            ], 500);
        }

        return $this->json([
            'ok' => true,
            'query' => $q,
            'count' => \count($ids),
            'spoolIds' => $ids,
        ]);
    }

    /** Inventurní tabulka (seskupení dle vláken a family; stejná logika jako PDF Přehled kabelových cívek). */
    #[Route('/inventura', name: 'inventura', methods: ['GET'])]
    public function inventura(SpoolRepository $spoolRepository): Response
    {
        $spools = $spoolRepository->findForInventuraSheet();
        $groups = self::buildInventuryGroups($spools);

        $generatedAt = new \DateTimeImmutable('now');

        return $this->render('warehouse/stock_browse_inventura.html.twig', [
            'groups' => $groups,
            'generatedAt' => $generatedAt,
        ]);
    }

    #[Route('/inventura/export/krata', name: 'inventura_export_brief', methods: ['GET'])]
    public function inventuraExportBrief(SpoolRepository $spoolRepository, InventuraExcelExporter $exporter): Response
    {
        $generatedAt = new \DateTimeImmutable('now');
        $groups = self::buildInventuryGroups($spoolRepository->findForInventuraSheet());

        return $exporter->downloadBrief($groups, $generatedAt);
    }

    #[Route('/inventura/export/plna', name: 'inventura_export_full', methods: ['GET'])]
    public function inventuraExportFull(SpoolRepository $spoolRepository, InventuraExcelExporter $exporter): Response
    {
        $generatedAt = new \DateTimeImmutable('now');
        $groups = self::buildInventuryGroups($spoolRepository->findForInventuraSheet());

        return $exporter->downloadFull($groups, $generatedAt);
    }

    /**
     * Experimentální výpis odběru dle zakázky: seskupení řádků jako krátká inventura (vl. · family · Ø),
     * bez jednoho součtu přes všechny typy v projektu.
     */
    #[Route('/odber-v-zakazkach', name: 'usage_by_project', methods: ['GET'])]
    public function usageByProject(Request $request, SpoolEventRepository $eventRepository): Response
    {
        $today = new \DateTimeImmutable('today');
        /** Výchozí od: půl roku zpět („Filtr podle projektů“ bez parametrů v URL). */
        $defaultFrom = $today->modify('-6 months')->setTime(0, 0, 0);

        $fromDay = self::parseBrowseDateDay($request->query->get('dateFrom'));
        $toDay = self::parseBrowseDateDay($request->query->get('dateTo'));
        $fromDay ??= $defaultFrom;
        $toDay ??= $today;
        if ($fromDay > $toDay) {
            $tmp = $fromDay;
            $fromDay = $toDay;
            $toDay = $tmp;
        }
        $from = $fromDay->setTime(0, 0, 0);
        $to = $toDay->setTime(23, 59, 59);

        $events = $eventRepository->findUsageEventsForProjectsReport($from, $to);
        $projectGroups = self::buildProjectUsageGroupsInventuraStyle($events);

        return $this->render('warehouse/stock_browse_usage_by_project.html.twig', [
            'projectGroups' => $projectGroups,
            'periodFrom' => $from,
            'periodTo' => $to,
            'filterDateFrom' => $fromDay->format('Y-m-d'),
            'filterDateTo' => $toDay->format('Y-m-d'),
        ]);
    }

    /**
     * @param list<\App\Entity\Spool> $spools
     *
     * @return list<array{
     *   groupLabel: string,
     *   fiber: int,
     *   family: string,
     *   rows: list<\App\Entity\Spool>,
     *   sumM: int,
     *   sumR: int,
     *   spoolCount: int,
     *   minM: int|null,
     *   maxM: int|null,
     *   diameterKey: string
     * }>
     */
    private static function buildInventuryGroups(array $spools): array
    {
        $buckets = [];
        foreach ($spools as $s) {
            $fiber = $s->getEffectiveFiberCount();
            $family = '' !== $s->getFamily() ? $s->getFamily() : '—';
            $diamKey = InventuraBriefGroupLabel::normalizeDiameterKey($s->getEffectiveDiameterMm());
            $key = $fiber."\0".$family."\0".$diamKey;
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'fiber' => $fiber,
                    'family' => $family,
                    'diameterKey' => $diamKey,
                    'rows' => [],
                    'sumM' => 0,
                    'sumR' => 0,
                ];
            }
            $buckets[$key]['rows'][] = $s;
            $buckets[$key]['sumM'] += (int) ($s->getCurrentRemainingM() ?? 0);
            $buckets[$key]['sumR'] += (int) ($s->getReservedM() ?? 0);
        }
        foreach (array_keys($buckets) as $key) {
            $rows = $buckets[$key]['rows'];
            $buckets[$key]['spoolCount'] = \count($rows);
            $remain = [];
            foreach ($rows as $sp) {
                $m = $sp->getCurrentRemainingM();
                if (null !== $m) {
                    $remain[] = (int) $m;
                }
            }
            if ($remain === []) {
                $buckets[$key]['minM'] = null;
                $buckets[$key]['maxM'] = null;
            } else {
                $buckets[$key]['minM'] = \min($remain);
                $buckets[$key]['maxM'] = \max($remain);
            }
        }
        $list = \array_values($buckets);
        \usort($list, function (array $a, array $b): int {
            if ($a['fiber'] !== $b['fiber']) {
                return $a['fiber'] <=> $b['fiber'];
            }
            $fc = \strcmp((string) $a['family'], (string) $b['family']);
            if (0 !== $fc) {
                return $fc;
            }
            if ('' === $a['diameterKey'] && '' === $b['diameterKey']) {
                return 0;
            }
            if ('' === $a['diameterKey']) {
                return 1;
            }
            if ('' === $b['diameterKey']) {
                return -1;
            }

            return (float) $a['diameterKey'] <=> (float) $b['diameterKey'];
        });
        foreach ($list as $i => $g) {
            $list[$i]['groupLabel'] = InventuraBriefGroupLabel::format(
                $g['fiber'],
                (string) $g['family'],
                (string) $g['diameterKey'],
            );
        }

        return $list;
    }

    /**
     * Hodnota z query může být pole (status[], cableTypeIds[]) — {@see InputBag::get} pro takové klíče vyhodí.
     */
    private static function rawQueryValue(Request $request, string $key): mixed
    {
        $all = $request->query->all();

        return $all[$key] ?? null;
    }

    /** @return list<int> */
    private static function parseIdList(Request $request, string $key): array
    {
        $v = self::rawQueryValue($request, $key);
        if (null === $v) {
            $v = [];
        } elseif (!\is_array($v)) {
            $v = (is_scalar($v) && (string) $v !== '' && is_numeric($v) && (int) $v > 0) ? [ (int) $v ] : [];
        }
        $out = [];
        foreach ($v as $x) {
            if (is_numeric($x) && (int) $x > 0) {
                $out[] = (int) $x;
            }
        }
        if ($out === []) {
            return [];
        }

        return array_values(array_unique($out, \SORT_REGULAR));
    }

    /**
     * Při první návštěvě (žádný parametr status v URL) = výchozí „na skladě“.
     *
     * @return list<SpoolStatus>
     */
    private static function parseStatusList(Request $request): array
    {
        $v = self::rawQueryValue($request, 'status');
        if (null === $v) {
            return [SpoolStatus::InStock];
        }
        if (!\is_array($v)) {
            $v = \is_string($v) && $v !== '' ? [ $v ] : [];
        }
        $seen = [];
        foreach ($v as $x) {
            if (!\is_string($x) && !\is_int($x)) {
                continue;
            }
            $e = SpoolStatus::tryFrom((string) $x);
            if (null === $e) {
                continue;
            }
            $seen[$e->value] = $e;
        }
        if ($seen === []) {
            return [SpoolStatus::InStock];
        }

        return array_values($seen);
    }

    private static function parseCableTypeUnset(Request $request): bool
    {
        $v = $request->query->get('cableTypeUnset');

        return '1' === (string) $v || 1 === $v || true === $v;
    }

    /**
     * Zaškrtnuto „Vše“ (všechny typy + bez typu) → bez omezení v DB.
     * Všechny typy bez „bez typu“ → jen cívky s přiřazeným typem.
     * Jen „bez typu“ → c.id IS NULL.
     *
     * @param list<int> $selected
     * @param list<int> $allIds
     */
    private static function resolveCableTypeBrowseFilter(
        array $selected,
        bool $includeUnsetFromForm,
        array $allIds,
    ): CableTypeBrowseFilter {
        if ($selected === [] && !$includeUnsetFromForm) {
            return new CableTypeBrowseFilter();
        }

        $allCatalogSelected = false;
        if ($selected !== [] && $allIds !== []) {
            $a = $selected;
            \sort($a, \SORT_NUMERIC);
            $b = $allIds;
            \sort($b, \SORT_NUMERIC);
            $allCatalogSelected = $a === $b;
        }

        if ($allCatalogSelected) {
            if ($includeUnsetFromForm) {
                return new CableTypeBrowseFilter();
            }

            return new CableTypeBrowseFilter(onlyWithAssignedType: true);
        }

        if ($selected === [] && $includeUnsetFromForm) {
            return new CableTypeBrowseFilter(includeUnset: true);
        }

        return new CableTypeBrowseFilter(ids: $selected, includeUnset: $includeUnsetFromForm);
    }

    /**
     * Seskupení odběru podle zakázky a skupiny jako u krát. inventury (vl., family, Ø).
     * Bez jedné součtené hodnoty „celkem m“ přes více skupin — ta čísla nejsou srovnatelná cenově.
     *
     * @param list<\App\Entity\SpoolEvent> $events
     *
     * @return list<array{projectLabel: string, lines: list<array{groupLabel: string, meters: int}>}>
     */
    private static function buildProjectUsageGroupsInventuraStyle(array $events): array
    {
        /** @var array<string, array<string, array{groupLabel: string, meters: int}>> $nested */
        $nested = [];
        foreach ($events as $e) {
            $pl = \trim((string) $e->getProjectLabel());
            if ('' === $pl) {
                continue;
            }
            $um = $e->getUsedMeters();
            if (null === $um || $um < 1) {
                continue;
            }
            $sp = $e->getSpool();
            if (null === $sp) {
                continue;
            }
            $fiber = $sp->getEffectiveFiberCount();
            $family = '' !== $sp->getFamily() ? $sp->getFamily() : '—';
            $diamKey = InventuraBriefGroupLabel::normalizeDiameterKey($sp->getEffectiveDiameterMm());
            $bucketKey = $fiber."\0".$family."\0".$diamKey;
            $groupLabel = InventuraBriefGroupLabel::format($fiber, $family, $diamKey);
            if (!isset($nested[$pl])) {
                $nested[$pl] = [];
            }
            if (!isset($nested[$pl][$bucketKey])) {
                $nested[$pl][$bucketKey] = [
                    'groupLabel' => $groupLabel,
                    'meters' => 0,
                ];
            }
            $nested[$pl][$bucketKey]['meters'] += $um;
        }
        /** @var list<string> */
        $projectLabelsOrdered = \array_keys($nested);
        \usort(
            $projectLabelsOrdered,
            static function (string $a, string $b): int {
                return self::compareProjectLabelsByAffinity($a, $b);
            },
        );
        $out = [];
        foreach ($projectLabelsOrdered as $label) {
            $groups = $nested[$label];
            $lines = \array_values($groups);
            \usort(
                $lines,
                static function (array $a, array $b): int {
                    if ($a['meters'] !== $b['meters']) {
                        return $b['meters'] <=> $a['meters'];
                    }

                    return \strcmp($a['groupLabel'], $b['groupLabel']);
                },
            );
            $out[] = [
                'projectLabel' => $label,
                'lines' => $lines,
            ];
        }

        /** Odpis a předání — vždy na konec (likvidace, pak PŘEDÁČA). */
        $wo = SpoolMeterService::WRITEOFF_PROJECT_LABEL;
        $tr = SpoolMeterService::TRANSFER_PROJECT_LABEL;
        $regular = [];
        $writeoffBlock = null;
        $transferBlock = null;
        foreach ($out as $block) {
            $pl = $block['projectLabel'];
            if ($pl === $wo) {
                $writeoffBlock = $block;

                continue;
            }
            if ($pl === $tr) {
                $transferBlock = $block;

                continue;
            }
            $regular[] = $block;
        }
        if (null !== $writeoffBlock) {
            $regular[] = $writeoffBlock;
        }
        if (null !== $transferBlock) {
            $regular[] = $transferBlock;
        }

        return $regular;
    }

    /**
     * Podobné názvy vedle sebe: první slovo zakázky, pak přirozené řazení celého názvu
     * (Vltava, Vltava Jih, Vltava Sever).
     */
    private static function compareProjectLabelsByAffinity(string $a, string $b): int
    {
        $wa = self::projectLabelLeadingWord($a);
        $wb = self::projectLabelLeadingWord($b);
        $c = \strnatcasecmp($wa, $wb);
        if (0 !== $c) {
            return $c;
        }

        return \strnatcasecmp($a, $b);
    }

    /** První „slovo“ v názvu zakázky (pro seskupení variant stejného projektu). */
    private static function projectLabelLeadingWord(string $label): string
    {
        $label = \trim($label);
        if ('' === $label) {
            return '';
        }
        $lower = \mb_strtolower($label, 'UTF-8');
        if (1 === \preg_match('/^[\p{L}\p{N}]+/u', $lower, $m)) {
            return $m[0];
        }

        return $lower;
    }

    private static function parseNeedsCorrectionFilter(Request $request): bool
    {
        return $request->query->getBoolean('needsCorrection');
    }

    private static function parseBrowseDateDay(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw)) {
            return null;
        }
        $raw = \trim($raw);
        if ('' === $raw) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if (false === $d) {
            return null;
        }

        return $d->setTime(0, 0, 0);
    }
}
