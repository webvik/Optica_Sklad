<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Kde nabízet stažení APK: výchozí !beta_welcome; volitelně MOBILE_APK_DOWNLOAD_ENABLED.
 */
final class MobileApkSettings
{
    public function __construct(
        #[Autowire(env: 'bool:BETA_WELCOME_ENABLED')] private readonly bool $betaWelcomeEnabled,
        #[Autowire(env: 'default:mobile_apk_download_enabled_default:MOBILE_APK_DOWNLOAD_ENABLED')] private readonly ?string $mobileApkDownloadEnabledRaw = null,
    ) {
    }

    public function isDownloadEnabled(): bool
    {
        $raw = trim($this->mobileApkDownloadEnabledRaw ?? '');
        if ('' !== $raw) {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }

        return !$this->betaWelcomeEnabled;
    }

    public function isBetaWelcomeEnabled(): bool
    {
        return $this->betaWelcomeEnabled;
    }
}
