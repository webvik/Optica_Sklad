<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Warehouse\SkladovaKartaPrintQueue;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SkladovaKartaPrintQueueExtension extends AbstractExtension
{
    public function __construct(
        private readonly SkladovaKartaPrintQueue $queue,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('skladova_karta_print_queue_summary', $this->summary(...)),
        ];
    }

    /**
     * @return array{count: int, url: string}
     */
    public function summary(): array
    {
        $count = \count($this->queue->ids());

        return [
            'count' => $count,
            'url' => $this->urls->generate('warehouse_spool_karty_index'),
        ];
    }
}
