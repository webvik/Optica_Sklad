<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Spool;
use App\Service\Warehouse\SkladovaKartaShareTokenService;
use App\Service\Warehouse\SkladovaKartaWhatsAppContactList;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SkladovaKartaExtension extends AbstractExtension
{
    public function __construct(
        private readonly SkladovaKartaWhatsAppContactList $whatsappContacts,
        private readonly SkladovaKartaShareTokenService $shareTokens,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('skladova_karta_whatsapp_contacts', $this->whatsappContacts(...)),
            new TwigFunction('skladova_karta_share_download_url', $this->shareDownloadUrl(...)),
        ];
    }

    /**
     * @return list<array{id: int, label: string, phone: string, phoneDisplay: string}>
     */
    public function whatsappContacts(): array
    {
        return $this->whatsappContacts->list();
    }

    public function shareDownloadUrl(Spool $spool): string
    {
        return $this->shareTokens->downloadUrl($spool);
    }
}
