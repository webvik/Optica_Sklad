<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\MobileApkSettings;
use App\Support\BetaWelcomeContent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class BetaWelcomeExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire(env: 'BETA_WHATSAPP_PHONE')] private readonly string $betaWhatsappPhoneDigits,
        #[Autowire(env: 'bool:BETA_WELCOME_ENABLED')] private readonly bool $betaWelcomeEnabled,
        private readonly MobileApkSettings $mobileApkSettings,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('beta_welcome_enabled', $this->isEnabled(...)),
            new TwigFunction('beta_welcome_paragraphs', $this->paragraphs(...)),
            new TwigFunction('beta_whatsapp_contact_url', $this->whatsappUrl(...)),
            new TwigFunction('beta_whatsapp_contact_label', $this->whatsappLabel(...)),
            new TwigFunction('mobile_apk_download_enabled', $this->mobileApkDownloadEnabled(...)),
            new TwigFunction('mobile_apk_prod_page_url', $this->mobileApkProdPageUrl(...)),
        ];
    }

    public function isEnabled(): bool
    {
        return $this->betaWelcomeEnabled;
    }

    /** @return list<string> */
    public function paragraphs(): array
    {
        return BetaWelcomeContent::paragraphs();
    }

    public function whatsappUrl(): ?string
    {
        return BetaWelcomeContent::whatsappHref($this->betaWhatsappPhoneDigits);
    }

    public function whatsappLabel(): string
    {
        return BetaWelcomeContent::whatsappDisplayLabel($this->betaWhatsappPhoneDigits);
    }

    public function mobileApkDownloadEnabled(): bool
    {
        return $this->mobileApkSettings->isDownloadEnabled();
    }

    public function mobileApkProdPageUrl(): string
    {
        return BetaWelcomeContent::PROD_MOBILE_PAGE_URL;
    }
}
