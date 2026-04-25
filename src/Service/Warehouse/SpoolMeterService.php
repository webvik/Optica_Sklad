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

    public function applyMeterReading(
        Spool $spool,
        int $newVisibleM,
        ?\DateTimeImmutable $occurredAt,
        ?string $projectLabel,
        ?User $user,
    ): SpoolEvent {
        $occurredAt ??= new \DateTimeImmutable();
        $prevM = $spool->getLastVisibleM() ?? $spool->getInitialVisibleM();
        $used = abs($newVisibleM - $prevM);
        $nMeterBefore = $this->countMeterReadings($spool);
        if (0 === $nMeterBefore && 0 === $used) {
            throw new RuntimeException('Oproti předchozímu čtení není žádná změna; zadejte novou hodnotu po odběru kabelu.');
        }

        $rem = $spool->getCurrentRemainingM() ?? $spool->getTotalLengthM();
        if ($used > $rem) {
            throw new RuntimeException(sprintf('Odběr %d m přesahuje zůstatek %d m.', $used, $rem));
        }

        if (0 === $nMeterBefore && null === $spool->getMeterSign() && 0 !== $newVisibleM - $spool->getInitialVisibleM()) {
            $spool->setMeterSign($newVisibleM > $spool->getInitialVisibleM() ? 1 : -1);
        } elseif ($nMeterBefore > 0 && null !== $spool->getMeterSign() && 0 !== $newVisibleM - $prevM) {
            $stepSign = $newVisibleM > $prevM ? 1 : ($newVisibleM < $prevM ? -1 : 0);
            if (0 !== $stepSign && $stepSign !== $spool->getMeterSign()) {
                throw new RuntimeException('Směr čísla na metru se neshoduje s evidovaným pro tuto cívku; zkontrolujte zadání.');
            }
        }

        $spool->setCurrentRemainingM($rem - $used);
        $spool->setLastVisibleM($newVisibleM);

        $event = new SpoolEvent();
        $event->setSpool($spool);
        $event->setType(SpoolEventType::MeterReading);
        $event->setOccurredAt($occurredAt);
        $event->setVisibleM($newVisibleM);
        $event->setUsedMeters($used);
        $event->setProjectLabel($projectLabel);
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
}
