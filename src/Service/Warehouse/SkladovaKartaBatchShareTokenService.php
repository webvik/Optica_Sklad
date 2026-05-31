<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\Spool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Odkaz ke stažení dávkového PDF skladových karet (48 h, bez přihlášení).
 */
final class SkladovaKartaBatchShareTokenService
{
    private const TTL_SECONDS = 172800;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<int> $spoolIds
     */
    public function createToken(array $spoolIds, ?\DateTimeImmutable $now = null): string
    {
        $ids = $this->normalizeIds($spoolIds);
        if ($ids === []) {
            throw new \InvalidArgumentException('Prázdný seznam cívek pro odkaz.');
        }

        $now ??= new \DateTimeImmutable();
        $expiry = $now->getTimestamp() + self::TTL_SECONDS;
        $payload = implode(',', $ids).'|'.$expiry;
        $sig = hash_hmac('sha256', $payload, $this->secret);

        return $this->encode($payload.'|'.$sig);
    }

    /**
     * @return list<int>|null
     */
    public function parseValidIds(string $token): ?array
    {
        $decoded = $this->decode($token);
        if (null === $decoded) {
            return null;
        }

        $parts = explode('|', $decoded);
        if (3 !== \count($parts)) {
            return null;
        }

        [$idsPart, $expiry, $sig] = $parts;
        $expected = hash_hmac('sha256', $idsPart.'|'.$expiry, $this->secret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        if ((int) $expiry < time()) {
            return null;
        }

        $ids = [];
        foreach (explode(',', $idsPart) as $raw) {
            if ('' === $raw) {
                continue;
            }
            $n = (int) $raw;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }

        $out = array_values($ids);
        sort($out, SORT_NUMERIC);

        return $out !== [] ? $out : null;
    }

    /**
     * @param list<Spool>|list<int> $spoolsOrIds
     */
    public function downloadUrl(array $spoolsOrIds): string
    {
        $ids = [];
        foreach ($spoolsOrIds as $item) {
            if ($item instanceof Spool) {
                $n = (int) $item->getId();
            } else {
                $n = (int) $item;
            }
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }

        return $this->urlGenerator->generate(
            'warehouse_spool_karty_share_pdf',
            ['token' => $this->createToken(array_values($ids))],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * @param list<int> $spoolIds
     *
     * @return list<int>
     */
    private function normalizeIds(array $spoolIds): array
    {
        $ids = [];
        foreach ($spoolIds as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
        $out = array_values($ids);
        sort($out, SORT_NUMERIC);

        return $out;
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
