<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Warehouse\SkladovaKartaWhatsAppContactList;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SkladovaKartaExtension extends AbstractExtension
{
    public function __construct(
        private readonly SkladovaKartaWhatsAppContactList $whatsappContacts,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('skladova_karta_whatsapp_contacts', $this->whatsappContacts(...)),
        ];
    }

    /**
     * @return list<array{id: int, label: string, phone: string, phoneDisplay: string}>
     */
    public function whatsappContacts(): array
    {
        return $this->whatsappContacts->list();
    }
}
