<?php

declare(strict_types=1);

namespace App\Service\Audit;

use Symfony\Component\HttpFoundation\Request;

/**
 * Redakce parametrů z request->request (formuláře) před uložením do auditního logu.
 */
final class FormPayloadRedactor
{
    private const MAX_STRING_LEN = 512;

    private const MAX_JSON_BYTES = 8192;

    /**
     * @return array<string, mixed>|null null = nic neukládat (prázdné nebo neformulářové)
     */
    public function redactFormParameters(Request $request): ?array
    {
        $method = strtoupper($request->getMethod());
        if (!\in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        if ($this->isLoginSubmit($request)) {
            return null;
        }

        $bag = $request->request->all();
        if ($bag === []) {
            return null;
        }

        $redacted = $this->redactArray($bag);
        if ($redacted === []) {
            return null;
        }

        $json = \json_encode($redacted, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        if (\strlen($json) > self::MAX_JSON_BYTES) {
            return [
                '_audit_large_payload' => true,
                '_audit_json_bytes' => \strlen($json),
            ];
        }

        return $redacted;
    }

    public function isLoginSubmit(Request $request): bool
    {
        if ('POST' !== strtoupper($request->getMethod())) {
            return false;
        }

        $routeRaw = $request->attributes->get('_route');

        return \is_string($routeRaw) && 'app_home' === $routeRaw;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function redactArray(array $data, int $depth = 0): array
    {
        if ($depth > 12) {
            return ['_audit_depth_limit' => true];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (!\is_string($key) && !\is_int($key)) {
                continue;
            }
            $name = (string) $key;
            if ($this->omitKey($name)) {
                continue;
            }
            if (\is_array($value)) {
                $nested = $this->redactArray($value, $depth + 1);
                if ($nested !== []) {
                    $out[$name] = $nested;
                }
            } elseif (\is_string($value)) {
                $out[$name] = $this->truncateString($value);
            } elseif (\is_scalar($value) || null === $value) {
                $out[$name] = $value;
            }
            // objekty (UploadedFile atd.) přeskočíme
        }

        return $out;
    }

    private function omitKey(string $key): bool
    {
        $k = strtolower($key);
        if ('_token' === $k || str_ends_with($k, '_token') || str_contains($k, 'csrf')) {
            return true;
        }
        if (preg_match('/password|passwd|secret|authorization|authenticat/', $k) === 1) {
            return true;
        }
        if (preg_match('/(^|_)pass($|_)/', $k) === 1) {
            return true;
        }

        return false;
    }

    private function truncateString(string $v): string
    {
        if (mb_strlen($v, 'UTF-8') <= self::MAX_STRING_LEN) {
            return $v;
        }

        return mb_substr($v, 0, self::MAX_STRING_LEN, 'UTF-8') . '…';
    }
}
