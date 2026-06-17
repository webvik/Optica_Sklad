<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Spool;
use App\Entity\SpoolEvent;
use App\Enum\SpoolEventType;
use App\Service\Warehouse\SpoolEventOrder;
use App\Service\Warehouse\SpoolMeterService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SpoolDiaryExtension extends AbstractExtension
{
    public function __construct(
        private readonly SpoolMeterService $meter,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('spool_diary_cells', $this->diaryCells(...)),
            new TwigFunction('spool_cable_end_visible_m', $this->cableEndVisibleM(...)),
            new TwigFunction('spool_event_author', $this->eventAuthor(...)),
            new TwigFunction('spool_event_edit_allow_visible_m', $this->eventEditAllowVisibleM(...)),
            new TwigFunction('spool_event_type_label', $this->eventTypeLabel(...)),
        ];
    }

    public function eventEditAllowVisibleM(Spool $spool, SpoolEvent $event): bool
    {
        return SpoolEventOrder::isLastVisibleChainEvent($spool, $event)
            && SpoolMeterService::isVisibleMeterChainEventType($event->getType());
    }

    public function eventTypeLabel(SpoolEventType $type): string
    {
        return match ($type) {
            SpoolEventType::MeterReading => 'zafuk',
            SpoolEventType::Transfer => 'předání',
            SpoolEventType::Writeoff => 'vyřazení',
            SpoolEventType::Inventory => 'inventura',
            SpoolEventType::Correction => 'korekce',
            SpoolEventType::LaidSection => 'úsek (štítek)',
        };
    }

    public function eventAuthor(SpoolEvent $event): string
    {
        $user = $event->getCreatedBy();
        if (null === $user) {
            return '—';
        }

        return $user->getDisplayName();
    }

    public function cableEndVisibleM(Spool $spool): ?int
    {
        return $this->meter->estimateCableEndVisibleM($spool);
    }

    /**
     * @return array{visibleM: ?int, usedMeters: ?int, projectLabel: ?string}
     */
    public function diaryCells(SpoolEvent $event): array
    {
        return $this->meter->diaryCells($event);
    }
}
