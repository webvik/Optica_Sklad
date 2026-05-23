<?php

namespace App\Controller\Warehouse;

use App\Enum\SpoolStatus;
use App\Repository\CableTypeRepository;
use App\Repository\ProjectReportAliasRepository;
use App\Repository\SpoolEventRepository;
use App\Repository\SpoolRepository;
use App\Security\WarehouseRole;
use App\Service\Warehouse\InventuraBriefGroupLabel;
use App\Service\Warehouse\InventuraExcelExporter;
use App\Service\Warehouse\ProjectReportAliasService;
use App\Service\Warehouse\SpoolMeterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        $cableTypeIdsForQuery = self::normalizeCableTypeIdsIfAllSelected($cableTypeIdsFromForm, $allCableTypeIds);

        $statuses = self::parseStatusList($request);
        $reelQ = \trim((string) $request->query->get('q', ''));

        return $this->render('warehouse/stock_browse.html.twig', [
            'spools' => $spools->findFiltered($cableTypeIdsForQuery, $statuses, '' !== $reelQ ? $reelQ : null),
            'searchQuery' => $reelQ,
            'cableTypeChoices' => $choiceEntities,
            'filterCableTypeIds' => $cableTypeIdsFromForm,
            'cableTypeFilterIsAll' => $cableTypeIdsForQuery === [] && $cableTypeIdsFromForm !== [],
            'cableTypeFilterIsNone' => $cableTypeIdsFromForm === [] && $cableTypeIdsForQuery === [],
            'allStatusesSelected' => \count($statuses) === \count(SpoolStatus::cases()),
            'filterStatusValues' => array_map(
                static fn (SpoolStatus $s) => $s->value,
                $statuses,
            ),
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
        $cableTypeIdsForQuery = self::normalizeCableTypeIdsIfAllSelected($cableTypeIdsFromForm, $allCableTypeIds);
        $statuses = self::parseStatusList($request);

        try {
            $ids = $spools->searchIdsByReelWithinFilters($q, $cableTypeIdsForQuery, $statuses, 500);
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
    public function usageByProject(
        Request $request,
        SpoolEventRepository $eventRepository,
        ProjectReportAliasService $aliasService,
        ProjectReportAliasRepository $aliasRepository,
    ): Response {
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
        $projectGroups = self::buildProjectUsageGroupsInventuraStyle($events, $aliasService);

        return $this->render('warehouse/stock_browse_usage_by_project.html.twig', [
            'projectGroups' => $projectGroups,
            'periodFrom' => $from,
            'periodTo' => $to,
            'filterDateFrom' => $fromDay->format('Y-m-d'),
            'filterDateTo' => $toDay->format('Y-m-d'),
            'projectAliasRows' => $aliasRepository->findAllOrdered(),
            'reportReservedLabels' => [
                SpoolMeterService::WRITEOFF_PROJECT_LABEL,
                SpoolMeterService::TRANSFER_PROJECT_LABEL,
            ],
        ]);
    }

    #[Route('/odber-v-zakazkach/sloucit', name: 'usage_by_project_merge', methods: ['POST'])]
    #[IsGranted(WarehouseRole::EDIT)]
    public function usageByProjectMerge(Request $request, ProjectReportAliasService $aliasService): Response
    {
        if (!$this->isCsrfTokenValid('project_alias_merge', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Neplatný formulář (CSRF).');

            return $this->redirectUsageByProject($request);
        }
        try {
            $canonical = (string) $request->request->get('canonicalLabel', '');
            $sources = $request->request->all('sourceLabels');
            if (!\is_array($sources)) {
                $sources = [];
            }
            $sources = array_values(array_filter(array_map(
                static fn ($v) => \is_string($v) ? $v : '',
                $sources,
            ), static fn (string $s): bool => '' !== \trim($s)));
            $aliasService->mergeBulk($canonical, $sources);
            $this->addFlash('success', 'Sloučení bylo uloženo — v reportu se vybrané projekty zobrazí pod jedním názvem. Zápis na cívce v deníku zůstává beze změny.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectUsageByProject($request);
    }

    #[Route('/odber-v-zakazkach/alias/{id}/smazat', name: 'usage_by_project_alias_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(WarehouseRole::EDIT)]
    public function usageByProjectAliasDelete(int $id, Request $request, ProjectReportAliasService $aliasService): Response
    {
        if (!$this->isCsrfTokenValid('project_alias_delete_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Neplatný formulář (CSRF).');

            return $this->redirectUsageByProject($request);
        }
        try {
            $aliasService->remove($id);
            $this->addFlash('success', 'Pravidlo sloučení bylo zrušeno.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectUsageByProject($request);
    }

    private function redirectUsageByProject(Request $request): Response
    {
        $dateFrom = \trim((string) $request->request->get('dateFrom', $request->query->get('dateFrom', '')));
        $dateTo = \trim((string) $request->request->get('dateTo', $request->query->get('dateTo', '')));
        $params = [];
        if ('' !== $dateFrom) {
            $params['dateFrom'] = $dateFrom;
        }
        if ('' !== $dateTo) {
            $params['dateTo'] = $dateTo;
        }

        return $this->redirectToRoute('warehouse_browse_usage_by_project', $params);
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

    /**
     * Zaškrtnuto „Vše“ = všechny typy v seznamu → v DB bez omezení (vč. cívek bez typu).
     *
     * @param list<int> $selected
     * @param list<int> $allIds
     *
     * @return list<int>
     */
    private static function normalizeCableTypeIdsIfAllSelected(array $selected, array $allIds): array
    {
        if ($selected === [] || $allIds === []) {
            return $selected;
        }
        if (\count($selected) !== \count($allIds)) {
            return $selected;
        }
        $a = $selected;
        \sort($a, \SORT_NUMERIC);

        return $a === $allIds ? [] : $selected;
    }

    /**
     * Seskupení odběru podle zakázky a skupiny jako u krát. inventury (vl., family, Ø).
     * Bez jedné součtené hodnoty „celkem m“ přes více skupin — ta čísla nejsou srovnatelná cenově.
     *
     * @param list<\App\Entity\SpoolEvent> $events
     *
     * @return list<array{
     *   projectLabel: string,
     *   sourceLabels: list<string>,
     *   lines: list<array{groupLabel: string, meters: int}>
     * }>
     */
    private static function buildProjectUsageGroupsInventuraStyle(
        array $events,
        ProjectReportAliasService $aliasService,
    ): array {
        $aliasMap = $aliasService->getNormalizedToCanonicalMap();
        /**
         * @var array<string, array{
         *   displayLabel: string,
         *   labelCounts: array<string, int>,
         *   sourceLabels: array<string, true>,
         *   buckets: array<string, array{groupLabel: string, meters: int}>
         * }>
         */
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
            $groupKey = $aliasService->reportGroupKey($pl, $aliasMap);
            $fiber = $sp->getEffectiveFiberCount();
            $family = '' !== $sp->getFamily() ? $sp->getFamily() : '—';
            $diamKey = InventuraBriefGroupLabel::normalizeDiameterKey($sp->getEffectiveDiameterMm());
            $bucketKey = $fiber."\0".$family."\0".$diamKey;
            $groupLabel = InventuraBriefGroupLabel::format($fiber, $family, $diamKey);
            if (!isset($nested[$groupKey])) {
                $nested[$groupKey] = [
                    'displayLabel' => $pl,
                    'labelCounts' => [],
                    'sourceLabels' => [],
                    'buckets' => [],
                ];
            }
            $nested[$groupKey]['sourceLabels'][$pl] = true;
            $nested[$groupKey]['labelCounts'][$pl] = ($nested[$groupKey]['labelCounts'][$pl] ?? 0) + 1;
            if (!isset($nested[$groupKey]['buckets'][$bucketKey])) {
                $nested[$groupKey]['buckets'][$bucketKey] = [
                    'groupLabel' => $groupLabel,
                    'meters' => 0,
                ];
            }
            $nested[$groupKey]['buckets'][$bucketKey]['meters'] += $um;
        }
        $out = [];
        foreach ($nested as $entry) {
            $entry['displayLabel'] = $aliasService->pickDisplayLabelFromVariants($entry['labelCounts']);
            $lines = \array_values($entry['buckets']);
            \usort(
                $lines,
                static function (array $a, array $b): int {
                    if ($a['meters'] !== $b['meters']) {
                        return $b['meters'] <=> $a['meters'];
                    }

                    return \strcmp($a['groupLabel'], $b['groupLabel']);
                },
            );
            $sourceLabels = \array_keys($entry['sourceLabels']);
            \usort($sourceLabels, static fn (string $a, string $b): int => \strnatcasecmp($a, $b));
            $out[] = [
                'projectLabel' => $entry['displayLabel'],
                'sourceLabels' => $sourceLabels,
                'lines' => $lines,
            ];
        }
        \usort(
            $out,
            static function (array $a, array $b): int {
                return self::compareProjectLabelsByAffinity($a['projectLabel'], $b['projectLabel']);
            },
        );

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
