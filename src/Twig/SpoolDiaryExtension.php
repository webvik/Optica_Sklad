<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Spool;
use App\Entity\SpoolEvent;
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
        ];
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
