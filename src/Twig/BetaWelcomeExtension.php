<?php

declare(strict_types=1);

namespace App\Twig;

use App\Support\BetaWelcomeContent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class BetaWelcomeExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire(env: 'BETA_WHATSAPP_PHONE')] private readonly string $betaWhatsappPhoneDigits,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('beta_welcome_paragraphs', $this->paragraphs(...)),
            new TwigFunction('beta_whatsapp_contact_url', $this->whatsappUrl(...)),
            new TwigFunction('beta_whatsapp_contact_label', $this->whatsappLabel(...)),
        ];
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
}
