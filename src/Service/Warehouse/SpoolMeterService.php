<?php

namespace App\Service\Warehouse;

use App\Entity\Spool;
use App\Entity\SpoolEvent;
use App\Entity\User;
use App\Enum\SpoolEventType;
use App\Enum\SpoolStatus;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Účtování odběru a aktualizace mezipaměti na cívce (metry celá čísla).
 */
final class SpoolMeterService
{
    /** K jednomu fyzickému odběru: částku |Δm| srovnáme s délkou kabelu na cívce (+ 20 %). U řady mlt doplňte strop podle běžné praxe. */
    private const ODBĚR_KROK_TOLERANCE_K_DÉLCE = 1.20;

    /** Řada mlt: běžně v praxi cívky cca do této délky — pro strop v kombinaci s procenty. */
    private const MLT_MAX_TYPICAL_CÍVKA_M = 6000;

    /** Kandidátní rozsahy čítače (4–6 „číslic“) pro odhad jednoho přetočení. */
    private const METER_MOD_CANDIDATES = [10_000, 100_000, 1_000_000];

    /** Popisek do sloupce „Zakázka“ u odpisu — odpis z evidence a likvidace fyz. zbytku. */
    public const WRITEOFF_PROJECT_LABEL = 'Odpis z evidence — likvidace zbytku';

    /** Popisek do sloupce „Zakázka“ u předání cívky (deník). */
    public const TRANSFER_PROJECT_LABEL = 'PŘEDÁČA';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function countMeterReadings(Spool $spool): int
    {
        $n = 0;
        foreach ($spool->getEvents() as $ev) {
            if (SpoolEventType::MeterReading === $ev->getType()) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * Počet záznamů o spotřebě m v řetězci: odběr dle metru + úsek (štítek) s m.
     * Pro kontrolu směru první všechny kroky, nejen „odběr dle metru“.
     */
    public function countVisibleChainEvents(Spool $spool): int
    {
        $n = 0;
        foreach ($spool->getEvents() as $ev) {
            if (self::isVisibleMeterChainEventType($ev->getType())) {
                ++$n;
            }
        }

        return $n;
    }

    /** Zafuk (metr) a historicky úsek/štítek (laid_section) — obojí s četbou m na místě. */
    public static function isVisibleMeterChainEventType(SpoolEventType $t): bool
    {
        return SpoolEventType::MeterReading === $t || SpoolEventType::LaidSection === $t;
    }

    /**
     * Poslední čtení m ve viditelném řetězci (zafuk / úsek štítek) podle chronologie zápisu (ID řádků).
     */
    public static function chronologicallyLatestChainVisibleM(Spool $spool): ?int
    {
        $last = null;
        foreach ($spool->getEvents() as $e) {
            if (!self::isVisibleMeterChainEventType($e->getType())) {
                continue;
            }
            $v = $e->getVisibleM();
            if (null !== $v) {
                $last = $v;
            }
        }

        return $last;
    }

    /**
     * Referenční m_{n−1} pro náhled kroku (Práce s optikou) — jako $prevM v {@see applyVisibleChainEvent},
     * jen pokud last_visible_m = 0 a poslední záznam v deníku ukazuje nenulové čtení, bere se koncové čtení
     * z deníku (oprava nesouladu cache po přetočení nuly / importu).
     */
    public function previewPrevVisibleForMeterStep(Spool $spool): int
    {
        $m0 = $spool->getInitialVisibleM();
        $nPrev = $this->countVisibleChainEvents($spool);
        if ($nPrev === 0) {
            return $m0;
        }
        $stored = $spool->getLastVisibleM();
        if (null === $stored) {
            return $m0;
        }
        /** @see applyVisibleChainEvent */
        $chainTail = self::chronologicallyLatestChainVisibleM($spool);
        if (0 === $stored && null !== $chainTail && 0 !== $chainTail) {
            return $chainTail;
        }

        return $stored;
    }

    public function applyMeterReading(
        Spool $spool,
        int $newVisibleM,
        ?\DateTimeImmutable $occurredAt,
        ?string $projectLabel,
        ?string $note,
        ?User $user,
    ): SpoolEvent {
        return $this->applyVisibleChainEvent(
            $spool,
            SpoolEventType::MeterReading,
            $newVisibleM,
            $occurredAt,
            $projectLabel,
            $note,
            $user
        );
    }

    /**
     * Jeden krok v řetězci: před tím míň viditelné m na cívce, víc spotřebováno fyzicky.
     * Odběr = |mₙ − mₙ₋₁| kde mₙ₋₁ = last_visible m po poslední události (ne u prvního kroku).
     *
     * První krok v řetězci (žádná událost meter/laid s m v deníku): referencí je vždy PS (m0).
     * Je-li lineární |Δm| větší než rozumný max. (délka cívky + 20 %), zkouší se jedno přetočení
     * čítače (mod 10⁴…10⁶), fyzický odběr se spočítá a určí směr metru. Dále viz
     * {@see self::resolveSingleStepOdběrWithOptionalWrap}.
     */
    public function applyVisibleChainEvent(
        Spool $spool,
        SpoolEventType $type,
        int $newVisibleM,
        ?\DateTimeImmutable $occurredAt,
        ?string $projectLabel,
        ?string $note,
        ?User $user,
    ): SpoolEvent {
        if (!self::isVisibleMeterChainEventType($type)) {
            throw new \InvalidArgumentException('Očekáván zafuk (metr) nebo (historicky) úsek/štítek s m.');
        }
        $occurredAt ??= new \DateTimeImmutable();
        $m0 = $spool->getInitialVisibleM();
        $nPrev = $this->countVisibleChainEvents($spool);
        // První záznam v řetězci: referencí je PS; u dalšího kroků musí předchozí m odpovídat řetězci po deníku
        // ({@see previewPrevVisibleForMeterStep}: oprava rozporu cached last_visible = 0 vs poslední záznam v deníku).
        $prevM = $this->previewPrevVisibleForMeterStep($spool);
        if (0 === $nPrev && $newVisibleM === $m0) {
            throw new RuntimeException('Bez kroku: zadejte čtení metru odlišné od PS (počáteční stav na kabelu).');
        }
        if ($nPrev > 0 && $newVisibleM === $prevM) {
            throw new RuntimeException('Oproti předchozímu čtení není žádná změna; zadejte jinou hodnotu m.');
        }
        $rem = $spool->getCurrentRemainingM() ?? $spool->getTotalLengthM();
        $signStored = $spool->getMeterSign();
        $resolved = $this->resolveSingleStepOdběrWithOptionalWrap(
            $spool,
            $nPrev,
            $m0,
            $prevM,
            $newVisibleM,
            $rem,
            $signStored
        );
        if (null === $resolved) {
            throw new RuntimeException(
                'Nelze spočítat fyzický odběr: zadejte čtení znovu, nebo zůstatek v evidenci neumožní ani variantu s přetočením čítače (mod 10 000 / 100 000 / 1 000 000 m).'
            );
        }
        $used = $resolved['used'];
        if (0 === $nPrev) {
            $spool->setMeterSign($resolved['inferredSign']);
        }

        $spool->setCurrentRemainingM($rem - $used);
        $spool->setLastVisibleM($newVisibleM);

        $event = new SpoolEvent();
        $event->setSpool($spool);
        $event->setType($type);
        $event->setOccurredAt($occurredAt);
        $event->setVisibleM($newVisibleM);
        $event->setUsedMeters($used);
        $event->setProjectLabel($projectLabel);
        $event->setNote($this->appendWrapNoteToEventNote(
            $note,
            $resolved['wrap'] ?? false,
            $resolved['mod'] ?? null
        ));
        $event->setCreatedBy($user);
        $spool->addEvent($event);
        $this->em->persist($event);
        $this->em->persist($spool);

        return $event;
    }

    private function maxFyzickýKrokDleCívky(Spool $spool): int
    {
        $total = $spool->getTotalLengthM();
        if ($total < 1) {
            return \PHP_INT_MAX;
        }
        $max = (int) \ceil($total * self::ODBĚR_KROK_TOLERANCE_K_DÉLCE);
        $fam = \strtolower(\trim($spool->getFamily() ?? ''));
        if ($fam !== '' && \str_contains($fam, 'mlt')) {
            $max = \min($max, (int) \ceil(self::MLT_MAX_TYPICAL_CÍVKA_M * self::ODBĚR_KROK_TOLERANCE_K_DÉLCE));
        }

        return $max;
    }

    private function appendWrapNoteToEventNote(?string $userNote, bool $wrap, ?int $mod): ?string
    {
        if (!$wrap) {
            return $userNote;
        }
        $s = ' [přetočení čítače, mod '.$mod.']';
        if (null === $userNote || '' === \trim($userNote)) {
            return $s;
        }

        return \rtrim($userNote).$s;
    }

    /**
     * @return array{used: int, inferredSign: int, wrap: bool, mod: int|null}|null
     */
    private function resolveSingleStepOdběrWithOptionalWrap(
        Spool $spool,
        int $nPrev,
        int $m0,
        int $prevM,
        int $newM,
        int $rem,
        ?int $signStored,
    ): ?array {
        $maxFyz = $this->maxFyzickýKrokDleCívky($spool);
        $cands = [];
        if (0 === $nPrev) {
            if ($newM > $m0) {
                $cands[] = ['d' => $newM - $m0, 'sign' => 1, 'wrap' => false, 'mod' => null];
                foreach (self::METER_MOD_CANDIDATES as $mod) {
                    if ($m0 >= $mod || $newM >= $mod) {
                        continue;
                    }
                    $cands[] = ['d' => $m0 + ($mod - $newM), 'sign' => -1, 'wrap' => true, 'mod' => $mod];
                }
            } elseif ($newM < $m0) {
                $cands[] = ['d' => $m0 - $newM, 'sign' => -1, 'wrap' => false, 'mod' => null];
                foreach (self::METER_MOD_CANDIDATES as $mod) {
                    if ($m0 >= $mod || $newM >= $mod) {
                        continue;
                    }
                    $cands[] = ['d' => ($mod - $m0) + $newM, 'sign' => 1, 'wrap' => true, 'mod' => $mod];
                }
            }
        } elseif (null !== $signStored) {
            if (1 === $signStored) {
                if ($newM > $prevM) {
                    $cands[] = ['d' => $newM - $prevM, 'wrap' => false, 'mod' => null];
                } elseif ($newM < $prevM) {
                    foreach (self::METER_MOD_CANDIDATES as $mod) {
                        if ($prevM >= $mod || $newM >= $mod) {
                            continue;
                        }
                        $cands[] = ['d' => ($mod - $prevM) + $newM, 'wrap' => true, 'mod' => $mod];
                    }
                }
            } else {
                if ($newM < $prevM) {
                    $cands[] = ['d' => $prevM - $newM, 'wrap' => false, 'mod' => null];
                } elseif ($newM > $prevM) {
                    foreach (self::METER_MOD_CANDIDATES as $mod) {
                        if ($prevM >= $mod || $newM >= $mod) {
                            continue;
                        }
                        $cands[] = ['d' => $prevM + ($mod - $newM), 'wrap' => true, 'mod' => $mod];
                    }
                }
            }
        }
        $valid = [];
        foreach ($cands as $c) {
            $d = $c['d'];
            if ($d < 1 || $d > $rem || $d > $maxFyz) {
                continue;
            }
            $valid[] = $c;
        }
        if ([] === $valid) {
            return null;
        }
        \usort(
            $valid,
            static function (array $a, array $b): int {
                if ($a['d'] !== $b['d']) {
                    return $a['d'] <=> $b['d'];
                }
                if ($a['wrap'] !== $b['wrap']) {
                    return $a['wrap'] <=> $b['wrap'];
                }

                return ($a['mod'] ?? 0) <=> ($b['mod'] ?? 0);
            }
        );
        $best = $valid[0];
        $inferred = 0 === $nPrev ? (int) ($best['sign'] ?? 0) : (int) $signStored;

        return [
            'used' => (int) $best['d'],
            'inferredSign' => $inferred,
            'wrap' => (bool) ($best['wrap'] ?? false),
            'mod' => $best['mod'] ?? null,
        ];
    }

    public function recordNonMeterEvent(
        Spool $spool,
        SpoolEventType $type,
        ?\DateTimeImmutable $occurredAt,
        ?int $visibleM,
        ?string $projectLabel,
        ?string $note,
        ?User $user,
    ): SpoolEvent {
        if (SpoolEventType::MeterReading === $type) {
            throw new \InvalidArgumentException();
        }
        $occurredAt ??= new \DateTimeImmutable();
        $event = new SpoolEvent();
        $event->setSpool($spool);
        $event->setType($type);
        $event->setOccurredAt($occurredAt);
        $event->setVisibleM($visibleM);
        $event->setProjectLabel($projectLabel);
        $event->setNote($note);
        $event->setCreatedBy($user);
        if (SpoolEventType::Writeoff === $type) {
            $spool->setStatus(SpoolStatus::WrittenOff);
        }
        if (SpoolEventType::Transfer === $type) {
            $remaining = $spool->getCurrentRemainingM() ?? $spool->getTotalLengthM();
            $notStarted = 0 === $this->countVisibleChainEvents($spool);
            $event->setProjectLabel(self::TRANSFER_PROJECT_LABEL);
            $event->setUsedMeters($remaining);
            $event->setVisibleM(
                $notStarted
                    ? $spool->getInitialVisibleM()
                    : ($spool->getLastVisibleM() ?? $spool->getInitialVisibleM())
            );
            $spool->setStatus(SpoolStatus::Transferred);
        }
        $spool->addEvent($event);
        $this->em->persist($event);
        $this->em->persist($spool);

        return $event;
    }

    /**
     * Vyřazení zbytku: v evidenci 0 m, stav written_off, v deníku krok = fyz. zbytek, bez fiktivního m na metru.
     */
    public function recordWriteoff(
        Spool $spool,
        \DateTimeImmutable $occurredAt,
        int $remainderM,
        ?string $note,
        ?User $user,
    ): SpoolEvent {
        if ($remainderM < 1) {
            throw new \InvalidArgumentException('Zbytek ke zrušení musí být alespoň 1 m.');
        }
        $book = $spool->getCurrentRemainingM();
        if (null === $book) {
            $book = $spool->getTotalLengthM();
        }
        if ($book < 1) {
            throw new RuntimeException('V evidenci není kabel ke zrušení (zůstatek 0 m).');
        }
        if ($remainderM > $book) {
            throw new RuntimeException('Zbytek ('.$remainderM.' m) je větší než zůstatek v evidenci ('.$book.' m).');
        }
        $event = new SpoolEvent();
        $event->setSpool($spool);
        $event->setType(SpoolEventType::Writeoff);
        $event->setOccurredAt($occurredAt);
        $event->setVisibleM(null);
        $event->setUsedMeters($remainderM);
        $event->setProjectLabel(self::WRITEOFF_PROJECT_LABEL);
        $event->setNote($note);
        $event->setCreatedBy($user);
        $spool->setStatus(SpoolStatus::WrittenOff);
        $spool->setCurrentRemainingM(0);
        $spool->addEvent($event);
        $this->em->persist($event);
        $this->em->persist($spool);

        return $event;
    }

    public function initNewSpoolState(Spool $spool): void
    {
        $spool->setCurrentRemainingM($spool->getTotalLengthM());
        $spool->setLastVisibleM($spool->getInitialVisibleM());
    }

    /**
     * Hodnoty pro tabulku deníku (může se lišit od surových polí u předání / vyřazení).
     *
     * @return array{visibleM: ?int, usedMeters: ?int, projectLabel: ?string}
     */
    public function diaryCells(SpoolEvent $event): array
    {
        $type = $event->getType();
        if (SpoolEventType::Transfer === $type) {
            return $this->diaryCellsForTransfer($event);
        }
        if (SpoolEventType::Writeoff === $type) {
            return [
                'visibleM' => null,
                'usedMeters' => $event->getUsedMeters(),
                'projectLabel' => $event->getProjectLabel() ?? self::WRITEOFF_PROJECT_LABEL,
            ];
        }

        return [
            'visibleM' => $event->getVisibleM(),
            'usedMeters' => $event->getUsedMeters(),
            'projectLabel' => $event->getProjectLabel(),
        ];
    }

    /**
     * @return array{visibleM: ?int, usedMeters: ?int, projectLabel: ?string}
     */
    private function diaryCellsForTransfer(SpoolEvent $event): array
    {
        $spool = $event->getSpool();
        if (null === $spool) {
            return [
                'visibleM' => $event->getVisibleM(),
                'usedMeters' => $event->getUsedMeters(),
                'projectLabel' => self::TRANSFER_PROJECT_LABEL,
            ];
        }

        $used = $event->getUsedMeters() ?? $spool->getCurrentRemainingM() ?? $spool->getTotalLengthM();
        $visible = $event->getVisibleM();
        if (null === $visible) {
            $notStarted = 0 === $this->countVisibleChainEventsBefore($spool, $event);
            $visible = $notStarted
                ? $spool->getInitialVisibleM()
                : ($spool->getLastVisibleM() ?? $spool->getInitialVisibleM());
        }

        return [
            'visibleM' => $visible,
            'usedMeters' => $used,
            'projectLabel' => self::TRANSFER_PROJECT_LABEL,
        ];
    }

    /** Řetězec m před daným záznamem (pro starší předání bez uloženého visible_m). */
    private function countVisibleChainEventsBefore(Spool $spool, SpoolEvent $before): int
    {
        $beforeId = $before->getId();
        $n = 0;
        foreach ($spool->getEvents() as $ev) {
            if (null !== $beforeId && null !== $ev->getId() && $ev->getId() >= $beforeId) {
                continue;
            }
            if (self::isVisibleMeterChainEventType($ev->getType())) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * Odvodí směr metru z řetězce záznamů s čtením m (kroky oproti předchozímu m; stálost směru
     * jako v {@see self::remainingForTableDisplay}).
     *
     * @return array{status: 'no_chain'|'no_nonzero_step'|'mixed_steps'|'conflicts_stored'|'unchanged'|'inferred', sign: int|null, inferred: int|null}
     */
    public function analyzeInferredMeterSignFromChain(Spool $spool): array
    {
        $m0 = $spool->getInitialVisibleM();
        $evs = SpoolEventOrder::byVisibleM($spool->getMeterSign(), $spool->getEvents()->toArray());
        $readings = \array_values(\array_filter(
            $evs,
            static fn (SpoolEvent $e): bool => self::isVisibleMeterChainEventType($e->getType())
        ));
        if ([] === $readings) {
            return ['status' => 'no_chain', 'sign' => $spool->getMeterSign(), 'inferred' => null];
        }
        $prev = $m0;
        $inferred = null;
        foreach ($readings as $e) {
            $newM = $e->getVisibleM();
            if (null === $newM) {
                continue;
            }
            $used = \abs($newM - $prev);
            $step = $newM > $prev ? 1 : ($newM < $prev ? -1 : 0);
            if ($used > 0) {
                if (0 !== $step) {
                    if (null === $inferred) {
                        $inferred = $step;
                    } elseif ($step !== $inferred) {
                        return ['status' => 'mixed_steps', 'sign' => $spool->getMeterSign(), 'inferred' => $inferred];
                    }
                }
            }
            $prev = $newM;
        }
        if (null === $inferred) {
            return ['status' => 'no_nonzero_step', 'sign' => $spool->getMeterSign(), 'inferred' => null];
        }
        $stored = $spool->getMeterSign();
        if (null !== $stored && $stored !== $inferred) {
            return ['status' => 'conflicts_stored', 'sign' => $stored, 'inferred' => $inferred];
        }
        if (null !== $stored && $stored === $inferred) {
            return ['status' => 'unchanged', 'sign' => $stored, 'inferred' => $inferred];
        }

        return ['status' => 'inferred', 'sign' => $inferred, 'inferred' => $inferred];
    }

    /**
     * Zobrazení zůstatku: hodnota vychází z current_remaining_m (a last_visible_m v workflow),
     * tedy z údajů u cívky po uložení odběrů, ne z přepočtu v paměti.
     * Doplňují se jen kontroly: směr čísel na metru (rostoucí/klesající), shoda s meterSign,
     * kontrola: celková délka (total_length_m) = zůstatek + součet odběrů z řetězce čtení m.
     *
     * @return array{
     *     remaining: int,
     *     directionOk: bool,
     *     directionLabel: string|null,
     *     warning: string|null
     * }
     */
    public function remainingForTableDisplay(Spool $spool): array
    {
        $total = $spool->getTotalLengthM();
        /** Zůstatek v evidenci (current_remaining_m); bez záznamu o odběru = celá délka kabelu (total_length_m) */
        $remaining = $spool->getCurrentRemainingM() ?? $total;
        $m0 = $spool->getInitialVisibleM();
        $evs = SpoolEventOrder::byVisibleM($spool->getMeterSign(), $spool->getEvents()->toArray());
        $readings = \array_values(\array_filter(
            $evs,
            static fn (SpoolEvent $e): bool => self::isVisibleMeterChainEventType($e->getType())
        ));

        if ([] === $readings) {
            $storedSign = $spool->getMeterSign();
            $label = null === $storedSign ? null : (1 === $storedSign ? 'rostoucí' : 'klesající');

            return [
                'remaining' => $remaining,
                'directionOk' => true,
                'directionLabel' => $label,
                'warning' => $remaining < 0 ? 'Záporný zůstatek v evidenci; zkontrolujte cívku.' : null,
            ];
        }

        $prev = $m0;
        $inferred = null;
        $dirOk = true;
        $warnings = [];
        $sumOdběrZŘetězce = 0;

        foreach ($readings as $e) {
            $newM = $e->getVisibleM();
            if (null === $newM) {
                continue;
            }
            $used = \abs($newM - $prev);
            $sumOdběrZŘetězce += $used;
            $step = $newM > $prev ? 1 : ($newM < $prev ? -1 : 0);
            if ($used > 0) {
                if (0 !== $step) {
                    if (null === $inferred) {
                        $inferred = $step;
                    } elseif ($step !== $inferred) {
                        $dirOk = false;
                        $warnings[] = 'Mezi záznamy s metrem není stálý směr (rostoucí nebo klesající) — zkontrolujte deník (zafuk nebo historicky úsek/štítek).';
                    }
                }
            }
            $prev = $newM;
        }

        $storedSign = $spool->getMeterSign();
        if (null !== $storedSign && null !== $inferred && $storedSign !== $inferred) {
            $dirOk = false;
            $warnings[] = 'Směr v deníku neodpovídá evidovanému směru (meter sign) u cívky.';
        }

        if (null === $storedSign && null !== $inferred) {
            $directionLabel = 1 === $inferred ? 'rostoucí' : 'klesající';
        } else {
            $directionLabel = null === $storedSign ? null : (1 === $storedSign ? 'rostoucí' : 'klesající');
        }

        if ($readings !== [] && null === $spool->getCurrentRemainingM()) {
            $warnings[] = 'V deníku jsou záznamy s metrem, ale u cívky chybí zůstatek (current_remaining_m) — doplňte podle evidence.';
        }
        if ($readings !== [] && $spool->getCurrentRemainingM() !== null) {
            $bal = $spool->getCurrentRemainingM() + $sumOdběrZŘetězce;
            if ($bal !== $total) {
                $warnings[] = \sprintf('Součet zůstatku a odběrů dle čtení m v deníku (%d + %d) se neshoduje s celkovou délkou v evidenci (%d m). Očekává se: délka = zůstatek + součet kroků |Δm| u odběrů a úseků.', $spool->getCurrentRemainingM(), $sumOdběrZŘetězce, $total);
            }
        }

        if ($remaining < 0) {
            $dirOk = false;
            $warnings[] = 'Záporný zůstatek v evidenci.';
        }

        $warning = [] !== $warnings ? \implode(' ', $warnings) : null;
        if (!$dirOk && null === $warning) {
            $warning = 'Nekonzistence směru metru; zkontrolujte záznamy.';
        }

        return [
            'remaining' => $remaining,
            'directionOk' => $dirOk,
            'directionLabel' => $directionLabel,
            'warning' => $warning,
        ];
    }

    /**
     * Přepočítá u cívky zůstatek, last_visible_m, meter_sign z chainu odběrů dle metru
     * (jako by se postupné zápisy provedly znovu). Volitelně srovná used_meters v událostech
     * s očekávanou hodnotu |mᵢ − mᵢ₋₁|.
     *
     * @return array{remaining: int, lastVisible: int, meterSign: int|null, warnings: list<string>, eventUsedMetersFixed: int}
     */
    public function recomputeSpoolStateFromMeterEvents(Spool $spool, bool $apply, bool $syncEventUsedMeters = true): array
    {
        $m0 = $spool->getInitialVisibleM();
        $total = $spool->getTotalLengthM();
        $evs = SpoolEventOrder::byVisibleM($spool->getMeterSign(), $spool->getEvents()->toArray());
        $readings = \array_values(\array_filter(
            $evs,
            static fn (SpoolEvent $e): bool => self::isVisibleMeterChainEventType($e->getType())
        ));

        if ([] === $readings) {
            return [
                'remaining' => $spool->getCurrentRemainingM() ?? $total,
                'lastVisible' => $spool->getLastVisibleM() ?? $m0,
                'meterSign' => $spool->getMeterSign(),
                'warnings' => [],
                'eventUsedMetersFixed' => 0,
            ];
        }

        $rem = $total;
        $prev = $m0;
        $meterSign = null;
        $nMeterBefore = 0;
        $warnings = [];
        $eventUsedMetersFixed = 0;

        foreach ($readings as $e) {
            $newM = $e->getVisibleM();
            if (null === $newM) {
                $warnings[] = 'Událost #'.($e->getId() ?? '?').' (řetězec m) nemá visible_m — v řetězci přeskočena.';
                continue;
            }
            $used = \abs($newM - $prev);
            if ($syncEventUsedMeters) {
                $oldUsed = $e->getUsedMeters();
                if (null === $oldUsed || $oldUsed !== $used) {
                    if ($apply) {
                        $e->setUsedMeters($used);
                        $this->em->persist($e);
                    }
                    ++$eventUsedMetersFixed;
                }
            }

            if (0 === $nMeterBefore && null === $meterSign && 0 !== $newM - $m0) {
                $meterSign = $newM > $m0 ? 1 : -1;
            } elseif ($nMeterBefore > 0 && null !== $meterSign && 0 !== $newM - $prev) {
                $stepSign = $newM > $prev ? 1 : ($newM < $prev ? -1 : 0);
                if (0 !== $stepSign && $stepSign !== $meterSign) {
                    $warnings[] = 'Událost #'.($e->getId() ?? '?').
                        ' (řetězec m): krok nerespektuje ustanovený směr metru; odběr (m) je stále započten.';
                }
            }

            $rem -= $used;
            $prev = $newM;
            ++$nMeterBefore;
        }

        if ($rem < 0) {
            $warnings[] = 'Přepočítaný zůstatek je záporný: '.$rem.' m (uloží se 0 m).';
        }
        $toStore = \max(0, $rem);

        if ($apply) {
            $spool->setCurrentRemainingM($toStore);
            $spool->setLastVisibleM($prev);
            $spool->setMeterSign($meterSign);
            $this->em->persist($spool);
        }

        return [
            'remaining' => $toStore,
            'lastVisible' => $prev,
            'meterSign' => $meterSign,
            'warnings' => $warnings,
            'eventUsedMetersFixed' => $eventUsedMetersFixed,
        ];
    }
}
