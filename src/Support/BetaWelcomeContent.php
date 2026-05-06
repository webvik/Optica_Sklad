<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Jednotný text beta informace (modál „Beta verze aplikace“).
 */
final class BetaWelcomeContent
{
    /** @var list<string> */
    private const PARAGRAPHS = [
        'Vysvětlení týkající se aktuálního fungování programu během beta testování:',
        'Hlavním úkolem programu Optický sklad je zobrazovat v reálném čase stav zásob optického kabelu ve skladové evidenci.',
        'Tento program lze používat jako zcela samostatný a nezávislý softwarový produkt, nebo jej lze integrovat do rozsáhlejší online platformy (například Stis) jako samostatný doplňkový modul. Vnějškově se to realizuje jako další položka v hlavním menu.',
        'Serverová část programu může být také doplněna o další klientskou část v podobě aplikace instalované do mobilního telefonu.',
        'Aby nedošlo ke zkreslení (poškození) reálných dat a aby bylo možné otestovat všechny možné provozní situace, program v současné době pracuje s TESTOVACÍMI údaji (jako jsou: čísla cívek, počáteční stav, objekty použití atd.), které jsou sice co nejvíce podobné reálným údajům, ale ne vždy se shodují s reálnými cívkami evidovanými v systému.',
        'Funkčnost programu, zejména pokud jde o přidávání různých typů filtrů pro analýzu a export dat do různých formátů (Excel, PDF), se v případě zájmu může výrazně rozšířit. V současné době se jedná pouze o standardně nezbytné a základní filtry.',
        'A na závěr, jelikož se jedná o první testovací beta verzi programu, obsahuje jednak mnoho nadbytečných vysvětlujících informací (popisy různých polí, tlačítek atd.), a jednak jsou možné chyby (případy nesprávného fungování), o kterých prosím nutně informujte vývojáře.',
        'Za účelem odhalení případných nesrovnalostí v chodu systému se všem uživatelům, kterým byl poskytnut přístup k seznámení se s programem a jeho testování, doporučuje co nejaktivněji prověřovat fungování programu při různých operacích (zadávání informací o použití kabelů na objektech, přidávání/vyřazování/předávání cívek, kontrola zobrazení všech operací v analytických přehledech/inventáři atd.).',
    ];

    /** @return list<string> */
    public static function paragraphs(): array
    {
        return self::PARAGRAPHS;
    }

    /** Čísla v mezinárodním formátu bez znaku „+“. */
    public static function normalizeWhatsappDigits(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);

        return $digits ?? '';
    }

    public static function whatsappHref(?string $rawDigits): ?string
    {
        $d = self::normalizeWhatsappDigits((string) $rawDigits);
        if ('' === $d) {
            return null;
        }

        return 'https://wa.me/'.$d;
    }

    /** Krátký popisek odkazu (+420 …) */
    public static function whatsappDisplayLabel(?string $rawDigits): string
    {
        $d = self::normalizeWhatsappDigits((string) $rawDigits);
        if ('' === $d) {
            return '';
        }
        if (\str_starts_with($d, '420') && \strlen($d) === 12) {
            $rest = substr($d, 3);

            return '+420 '.$rest[0].$rest[1].$rest[2].' '.$rest[3].$rest[4].' '.$rest[5].$rest[6].$rest[7].$rest[8];
        }

        return '+'.$d;
    }
}
