<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Spool;
use App\Service\Warehouse\SkladovaKartaShareTokenService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SkladovaKartaExtension extends AbstractExtension
{
    public function __construct(
        private readonly SkladovaKartaShareTokenService $shareTokens,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('skladova_karta_share_download_url', $this->shareDownloadUrl(...)),
        ];
    }

    public function shareDownloadUrl(Spool $spool): string
    {
        return $this->shareTokens->downloadUrl($spool);
    }
}
