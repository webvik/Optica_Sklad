<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\Spool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Jednorázový odkaz ke stažení skladové karty (bez přihlášení, platnost 48 h).
 * Pro odeslání přes WhatsApp (PDF — Web Share API neumí .xlsx).
 */
final class SkladovaKartaShareTokenService
{
    private const TTL_SECONDS = 172800;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function createToken(Spool $spool, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $expiry = $now->getTimestamp() + self::TTL_SECONDS;
        $payload = (string) $spool->getId().'|'.$expiry;
        $sig = hash_hmac('sha256', $payload, $this->secret);

        return $this->encode($payload.'|'.$sig);
    }

    public function isValid(Spool $spool, string $token): bool
    {
        $decoded = $this->decode($token);
        if (null === $decoded) {
            return false;
        }

        $parts = explode('|', $decoded);
        if (3 !== \count($parts)) {
            return false;
        }

        [$id, $expiry, $sig] = $parts;
        if ((int) $id !== (int) $spool->getId()) {
            return false;
        }

        if ((int) $expiry < time()) {
            return false;
        }

        $expected = hash_hmac('sha256', $id.'|'.$expiry, $this->secret);

        return hash_equals($expected, $sig);
    }

    public function downloadUrl(Spool $spool): string
    {
        return $this->urlGenerator->generate(
            'warehouse_spool_skladova_karta_share_pdf',
            [
                'id' => $spool->getId(),
                'token' => $this->createToken($spool),
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decode(string $token): ?string
    {
        $b64 = strtr($token, '-_', '+/');
        $pad = \strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($b64, true);

        return \is_string($raw) && '' !== $raw ? $raw : null;
    }
}
