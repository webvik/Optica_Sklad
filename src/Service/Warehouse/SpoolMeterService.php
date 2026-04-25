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

    /** Odběr dle metru a úsek dle štítku (laid_section) — obojí s četbou m na místě. */
    public static function isVisibleMeterChainEventType(SpoolEventType $t): bool
    {
        return SpoolEventType::MeterReading === $t || SpoolEventType::LaidSection === $t;
    }

    public function applyMeterReading(
        Spool $spool,
        int $newVisibleM,
        ?\DateTimeImmutable $occurredAt,
        ?string $projectLabel,
        ?User $user,
    ): SpoolEvent {
        return $this->applyVisibleChainEvent(
            $spool,
            SpoolEventType::MeterReading,
            $newVisibleM,
            $occurredAt,
            $projectLabel,
            null,
            $user
        );
    }

    /**
     * Jeden krok v řetězci: před tím míň viditelné m na cívce, víc spotřebováno fyzicky.
     * Předchozí m = last_visible_m (nebo initial při stavu startu), odběr = |mₙ − mₙ₋₁|,
     * zůstatek := zůstatek − odběr, last_visible_m := mₙ.
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
            throw new \InvalidArgumentException('Očekáván odběr dle metru nebo úsek dle štítku s m.');
        }
        $occurredAt ??= new \DateTimeImmutable();
        $m0 = $spool->getInitialVisibleM();
        $prevM = $spool->getLastVisibleM() ?? $m0;
        $used = \abs($newVisibleM - $prevM);
        $nPrev = $this->countVisibleChainEvents($spool);
        if (0 === $nPrev && 0 === $used) {
            throw new RuntimeException('Oproti předchozímu čtení není žádná změna; zadejte novou hodnotu po odběru kabelu.');
        }

        $rem = $spool->getCurrentRemainingM() ?? $spool->getTotalLengthM();
        if ($used > $rem) {
            throw new RuntimeException(\sprintf('Odběr %d m přesahuje zůstatek %d m.', $used, $rem));
        }

        if (0 === $nPrev && null === $spool->getMeterSign() && 0 !== $newVisibleM - $m0) {
            $spool->setMeterSign($newVisibleM > $m0 ? 1 : -1);
        } elseif ($nPrev > 0 && null !== $spool->getMeterSign() && 0 !== $newVisibleM - $prevM) {
            $stepSign = $newVisibleM > $prevM ? 1 : ($newVisibleM < $prevM ? -1 : 0);
            if (0 !== $stepSign && $stepSign !== $spool->getMeterSign()) {
                throw new RuntimeException('Směr čísla na metru se neshoduje s evidovaným pro tuto cívku; zkontrolujte zadání.');
            }
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
        $event->setNote($note);
        $event->setCreatedBy($user);
        $spool->addEvent($event);
        $this->em->persist($event);
        $this->em->persist($spool);

        return $event;
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
            $spool->setStatus(SpoolStatus::Transferred);
        }
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
     * Odvodí směr metru z řetězce záznamů s čtením m (kroky oproti předchozímu m; stálost směru
     * jako v {@see self::remainingForTableDisplay}).
     *
     * @return array{status: 'no_chain'|'no_nonzero_step'|'mixed_steps'|'conflicts_stored'|'unchanged'|'inferred', sign: int|null, inferred: int|null}
     */
    public function analyzeInferredMeterSignFromChain(Spool $spool): array
    {
        $m0 = $spool->getInitialVisibleM();
        $evs = $spool->getEvents()->toArray();
        \usort(
            $evs,
            static function (SpoolEvent $a, SpoolEvent $b): int {
                $t = $a->getOccurredAt() <=> $b->getOccurredAt();

                return 0 === $t ? ($a->getId() ?? 0) <=> ($b->getId() ?? 0) : $t;
            }
        );
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
        $evs = $spool->getEvents()->toArray();
        \usort(
            $evs,
            static function (SpoolEvent $a, SpoolEvent $b): int {
                $t = $a->getOccurredAt() <=> $b->getOccurredAt();

                return 0 === $t ? ($a->getId() ?? 0) <=> ($b->getId() ?? 0) : $t;
            }
        );
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
                        $warnings[] = 'Mezi záznamy s metrem není stálý směr (rostoucí nebo klesající) — zkontrolujte deník (odběr dle metru nebo úsek/štítek).';
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
        $evs = $spool->getEvents()->toArray();
        \usort(
            $evs,
            static function (SpoolEvent $a, SpoolEvent $b): int {
                $t = $a->getOccurredAt() <=> $b->getOccurredAt();

                return 0 === $t ? ($a->getId() ?? 0) <=> ($b->getId() ?? 0) : $t;
            }
        );
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
