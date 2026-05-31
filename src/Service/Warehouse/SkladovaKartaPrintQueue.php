<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Seznam ID cívek k tisku skladových karet v aktuální relaci (po zadání nových cívek).
 */
final class SkladovaKartaPrintQueue
{
    private const SESSION_KEY = 'skladova_karta_print_queue';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return list<int> */
    public function ids(): array
    {
        $raw = $this->session()->get(self::SESSION_KEY, []);
        if (!\is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            if (!\is_int($id) && !(\is_string($id) && ctype_digit($id))) {
                continue;
            }
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }

        return array_values($ids);
    }

    public function enqueue(int $spoolId): void
    {
        if ($spoolId <= 0) {
            return;
        }
        $ids = $this->ids();
        if (!\in_array($spoolId, $ids, true)) {
            $ids[] = $spoolId;
            $this->session()->set(self::SESSION_KEY, $ids);
        }
    }

    /** @param list<int> $spoolIds */
    public function enqueueMany(array $spoolIds): void
    {
        $merged = $this->ids();
        foreach ($spoolIds as $id) {
            $n = (int) $id;
            if ($n > 0 && !\in_array($n, $merged, true)) {
                $merged[] = $n;
            }
        }
        $this->session()->set(self::SESSION_KEY, $merged);
    }

    public function remove(int $spoolId): void
    {
        $ids = array_values(array_filter(
            $this->ids(),
            static fn (int $id): bool => $id !== $spoolId,
        ));
        $this->session()->set(self::SESSION_KEY, $ids);
    }

    public function removeMany(array $spoolIds): void
    {
        $drop = [];
        foreach ($spoolIds as $id) {
            $drop[(int) $id] = true;
        }
        $ids = array_values(array_filter(
            $this->ids(),
            static fn (int $id): bool => !isset($drop[$id]),
        ));
        $this->session()->set(self::SESSION_KEY, $ids);
    }

    public function clear(): void
    {
        $this->session()->remove(self::SESSION_KEY);
    }

    private function session(): Session
    {
        $session = $this->requestStack->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        return $session;
    }
}
