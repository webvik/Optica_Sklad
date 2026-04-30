<?php

namespace App\Controller\Warehouse;

use App\Enum\SpoolStatus;
use App\Service\Warehouse\InventuraBriefGroupLabel;
use App\Service\Warehouse\SpoolMeterService;
use App\Repository\CableTypeRepository;
use App\Repository\SpoolEventRepository;
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
        $choiceEntities = $cableTypes->findBy([], ['name' => 'ASC']);
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

        return $this->render('warehouse/stock_browse.html.twig', [
            'spools' => $spools->findFiltered($cableTypeIdsForQuery, $statuses),
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

    /** Inventurní tabulka (seskupení dle vláken a family; stejná logika jako PDF Přehled kabelových cívek). */
    #[Route('/inventura', name: 'inventura', methods: ['GET'])]
    public function inventura(SpoolRepository $spoolRepository): Response
    {
        $spools = $spoolRepository->findForInventuraSheet();
        $groups = self::buildInventuryGroups($spools);

        return $this->render('warehouse/stock_browse_inventura.html.twig', [
            'groups' => $groups,
            'generatedAt' => new \DateTimeImmutable('now'),
        ]);
    }

    /**
     * Experimentální výpis odběru dle zakázky: seskupení řádků jako krátká inventura (vl. · family · Ø),
     * bez jednoho součtu přes všechny typy v projektu.
     */
    #[Route('/odber-v-zakazkach', name: 'usage_by_project', methods: ['GET'])]
    public function usageByProject(Request $request, SpoolEventRepository $eventRepository): Response
    {
        $today = new \DateTimeImmutable('today');
        $defaultFrom = $today->modify('first day of this month')->setTime(0, 0, 0);

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

        /** Virtuální zakázka pro odpisy — na konec seznamu (viz {@see SpoolMeterService::WRITEOFF_PROJECT_LABEL}). */
        $wo = SpoolMeterService::WRITEOFF_PROJECT_LABEL;
        $withoutWriteoff = [];
        $writeoffBlock = null;
        foreach ($out as $block) {
            if ($block['projectLabel'] === $wo) {
                $writeoffBlock = $block;

                continue;
            }
            $withoutWriteoff[] = $block;
        }
        if (null !== $writeoffBlock) {
            $withoutWriteoff[] = $writeoffBlock;
        }

        return $withoutWriteoff;
    }

    /**
     * Řazení především podle slov (bez ohledu na pořadí), sekundárně čistě číselné kusy řetězce
     * (např. různé číslo zakázky u stejných slov jako „test“ budou pohromadě).
     */
    private static function compareProjectLabelsByAffinity(string $a, string $b): int
    {
        $pa = self::projectAffinityBuckets($a);
        $pb = self::projectAffinityBuckets($b);
        if ($pa['text'] !== $pb['text']) {
            return \strcmp($pa['text'], $pb['text']);
        }
        $nc = self::compareSortedNumericTokenLists($pa['nums'], $pb['nums']);
        if (0 !== $nc) {
            return $nc;
        }

        return \strnatcasecmp($a, $b);
    }

    /**
     * Rozdělí slova od číselných tokenů; text setřídit, pak čísla (pro sekundární pořadí).
     *
     * @return array{text: string, nums: list<string>}
     */
    private static function projectAffinityBuckets(string $label): array
    {
        $label = \trim($label);
        if ('' === $label) {
            return ['text' => '', 'nums' => []];
        }
        $lower = \mb_strtolower($label, 'UTF-8');
        $words = [];
        if (false !== \preg_match_all('/[\p{L}\p{N}]+/u', $lower, $m) && isset($m[0]) && $m[0] !== []) {
            $words = $m[0];
        }
        if ($words === []) {
            return ['text' => $lower, 'nums' => []];
        }
        $textTok = [];
        $numTok = [];
        foreach ($words as $w) {
            if (1 === \preg_match('/^\p{N}+$/u', $w)) {
                $numTok[] = $w;

                continue;
            }
            $textTok[] = $w;
        }
        \sort($textTok, \SORT_STRING);
        /* řazení číselných tokenů přirozeně (2 před 10) */
        \sort($numTok, \SORT_NATURAL);

        return ['text' => \implode(' ', $textTok), 'nums' => $numTok];
    }

    /** @param list<string> $a @param list<string> $b */
    private static function compareSortedNumericTokenLists(array $a, array $b): int
    {
        $na = \count($a);
        $nb = \count($b);
        $n = \min($na, $nb);
        for ($i = 0; $i < $n; ++$i) {
            $c = \strnatcmp((string) $a[$i], (string) $b[$i]);
            if (0 !== $c) {
                return $c;
            }
        }

        return $na <=> $nb;
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
