<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Dočasný odkaz přihlašovacích údajů před otevřením WhatsApp („wa.me…“).
 * Soubor se po úspěšném načtení pro přesměrování smaže; z DB heslo neodečtete (hash).
 */
final class UserCredentialsWhatsAppHandoff
{
    private const TTL_SECONDS = 7200;

    private readonly Filesystem $fs;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/whatsapp_handoff')]
        private readonly string $storageDir,
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * Vytvoří soubor „jednorázového“ odkazu. Vrací náhodný token (hex).
     */
    public function store(string $username, string $plainPassword, string $phoneDigitsWa): string
    {
        if (!preg_match('/^[0-9]{10,15}$/', $phoneDigitsWa)) {
            throw new \InvalidArgumentException('Neplatné číslo pro WhatsApp.');
        }

        $this->ensureDir();
        $this->purgeStaleFiles();

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $token = bin2hex(random_bytes(16));
            $path = $this->pathFor($token);
            if (is_file($path)) {
                continue;
            }

            $expiresAt = \time() + self::TTL_SECONDS;
            $payload = [
                'v' => 1,
                'expiresAt' => $expiresAt,
                'username' => $username,
                'plainPassword' => $plainPassword,
                'waDigits' => $phoneDigitsWa,
            ];

            try {
                $json = json_encode($payload, \JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $e) {
                throw new \RuntimeException('Nelze zapsat odkaz WhatsApp.', 0, $e);
            }

            $this->fs->dumpFile($path, $json);
            try {
                @chmod($path, 0600);
            } catch (\Throwable) {
            }

            return $token;
        }

        throw new \RuntimeException('Nepodařilo se vytvořit jednorázový odkaz.');
    }

    /**
     * Kontrola: soubor existuje a token ještě nevypršel (soubor neodstraňuje).
     */
    public function isReady(string $token): bool
    {
        try {
            $this->consume($token, delete: false);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return array{v: int, expiresAt: int, username: string, plainPassword: string, waDigits: string}
     */
    public function consume(string $token, bool $delete): array
    {
        $this->purgeStaleFiles();

        if (!$this->looksLikeToken($token)) {
            throw new \InvalidArgumentException('Neplatný token.');
        }

        $dirReal = realpath($this->storageDir);
        if (false === $dirReal) {
            throw new \InvalidArgumentException('Token je neplatný.');
        }

        $path = $this->pathFor($token);
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Token je neplatný.');
        }

        $pathReal = realpath($path);
        if (false === $pathReal || !str_starts_with($pathReal, $dirReal.\DIRECTORY_SEPARATOR)) {
            @unlink($path);

            throw new \InvalidArgumentException('Token je neplatný.');
        }

        $raw = @file_get_contents($pathReal);
        if (false === $raw || '' === $raw) {
            @unlink($pathReal);

            throw new \InvalidArgumentException('Token je neplatný.');
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            @unlink($pathReal);

            throw new \InvalidArgumentException('Token je neplatný.');
        }

        if (!\is_array($data) || !isset($data['expiresAt'], $data['username'], $data['plainPassword'], $data['waDigits'])) {
            @unlink($pathReal);

            throw new \InvalidArgumentException('Token je neplatný.');
        }

        $expiresAt = (int) $data['expiresAt'];
        $username = (string) $data['username'];
        $pwd = (string) $data['plainPassword'];
        $waDigits = (string) $data['waDigits'];

        if ($expiresAt < \time() || !preg_match('/^[0-9]{10,15}$/', $waDigits) || '' === $username || '' === $pwd) {
            @unlink($pathReal);

            throw new \InvalidArgumentException('Token vypršel nebo je neplatný.');
        }

        $out = [
            'v' => 1,
            'expiresAt' => $expiresAt,
            'username' => $username,
            'plainPassword' => $pwd,
            'waDigits' => $waDigits,
        ];

        if ($delete) {
            @unlink($pathReal);
        }

        return $out;
    }

    public function buildWaUrl(string $digits, string $message): string
    {
        return 'https://wa.me/'.$digits.'?text='.rawurlencode($message);
    }

    private function looksLikeToken(string $token): bool
    {
        return 32 === \strlen($token) && ctype_xdigit($token);
    }

    private function pathFor(string $token): string
    {
        return $this->storageDir.\DIRECTORY_SEPARATOR.$token.'.json';
    }

    private function ensureDir(): void
    {
        if (is_dir($this->storageDir)) {
            return;
        }

        try {
            $this->fs->mkdir($this->storageDir, 0700);
        } catch (IOException|\Throwable) {
            throw new \RuntimeException('Nepodařilo se vytvořit adresář pro odkaz WhatsApp.');
        }
    }

    /**
     * Vymaže všechny „mrtvé“ soubory: po expiraci, poškozený JSON, prázdný obsah.
     * Volá se při zápisu a při každém čtení consume() — tak aby se nic nehromadilo,
     * i když admin odkaz vůbec neotevře (první další požadavek do skladu admina to uklidí).
     */
    private function purgeStaleFiles(): void
    {
        if (!is_dir($this->storageDir)) {
            return;
        }

        $dirReal = realpath($this->storageDir);
        if (false === $dirReal || !is_readable($dirReal)) {
            return;
        }

        $now = \time();
        $pattern = $dirReal.\DIRECTORY_SEPARATOR.'*.json';
        $files = glob($pattern, \GLOB_NOSORT);
        if (false === $files) {
            return;
        }

        foreach ($files as $candidate) {
            if (!\is_string($candidate)) {
                continue;
            }
            $pathReal = realpath($candidate);
            if (false === $pathReal || !str_starts_with($pathReal, $dirReal.\DIRECTORY_SEPARATOR) || !is_file($pathReal)) {
                continue;
            }

            $raw = @file_get_contents($pathReal);
            if (false === $raw || '' === $raw) {
                @unlink($pathReal);

                continue;
            }

            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                @unlink($pathReal);

                continue;
            }

            if (!\is_array($data)) {
                @unlink($pathReal);

                continue;
            }

            $expiresAt = isset($data['expiresAt']) ? (int) $data['expiresAt'] : 0;
            if ($expiresAt <= 0 || $expiresAt < $now) {
                @unlink($pathReal);
            }
        }
    }
}
