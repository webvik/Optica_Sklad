<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Repository\UserRepository;
use App\Support\BetaWelcomeContent;

/**
 * Aktivní uživatelé s telefonem pro odeslání skladové karty k tisku (WhatsApp).
 */
final class SkladovaKartaWhatsAppContactList
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return list<array{id: int, label: string, phone: string, phoneDisplay: string}>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->users->findActiveWithPhoneOrdered() as $user) {
            $raw = trim((string) ($user->getPhone() ?? ''));
            if ('' === $raw) {
                continue;
            }
            $phone = BetaWelcomeContent::normalizeWhatsappDigits($raw);
            if ('' === $phone) {
                continue;
            }
            $out[] = [
                'id' => (int) $user->getId(),
                'label' => $user->getDisplayName(),
                'phone' => $phone,
                'phoneDisplay' => BetaWelcomeContent::whatsappDisplayLabel($raw),
            ];
        }

        return $out;
    }
}
