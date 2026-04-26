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
}
