<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\ProjectReportAlias;
use App\Repository\ProjectReportAliasRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ProjectReportAliasService
{
    public function __construct(
        private readonly ProjectReportAliasRepository $aliases,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function normalize(string $label): string
    {
        $label = \trim($label);
        if ('' === $label) {
            return '';
        }
        $label = \mb_strtolower($label, 'UTF-8');
        if (\function_exists('mb_convert_kana')) {
            $label = \mb_convert_kana($label, 's', 'UTF-8');
        }
        $label = (string) \preg_replace('/\s+/u', ' ', $label);

        return $label;
    }

    public function isReservedReportLabel(string $label): bool
    {
        $label = \trim($label);

        return SpoolMeterService::WRITEOFF_PROJECT_LABEL === $label
            || SpoolMeterService::TRANSFER_PROJECT_LABEL === $label;
    }

    /**
     * @param array<string, string> $normalizedToCanonical
     */
    public function resolveCanonical(string $rawLabel, array $normalizedToCanonical): string
    {
        $rawLabel = \trim($rawLabel);
        if ('' === $rawLabel || $this->isReservedReportLabel($rawLabel)) {
            return $rawLabel;
        }
        $norm = $this->normalize($rawLabel);
        if ('' === $norm) {
            return $rawLabel;
        }

        return $normalizedToCanonical[$norm] ?? $rawLabel;
    }

    /**
     * Klíč seskupení v reportu: explicitní alias, nebo stejný název bez ohledu na velikost písmen.
     */
    public function reportGroupKey(string $rawLabel, array $normalizedToCanonical): string
    {
        $rawLabel = \trim($rawLabel);
        if ('' === $rawLabel || $this->isReservedReportLabel($rawLabel)) {
            return $rawLabel;
        }
        $norm = $this->normalize($rawLabel);
        if ('' === $norm) {
            return $rawLabel;
        }
        if (isset($normalizedToCanonical[$norm])) {
            return $normalizedToCanonical[$norm];
        }

        return 'n:'.$norm;
    }

    /** Upřednostní zápis, který není celý velkými písmeny (Vltava před VLTAVA). */
    public function preferDisplayLabel(string $current, string $candidate): string
    {
        if ($this->isAllCapsLabel($current) && !$this->isAllCapsLabel($candidate)) {
            return $candidate;
        }
        if (!$this->isAllCapsLabel($current) && $this->isAllCapsLabel($candidate)) {
            return $current;
        }

        return $current;
    }

    private function isAllCapsLabel(string $label): bool
    {
        if (!\preg_match('/\p{L}/u', $label)) {
            return false;
        }

        return \mb_strtoupper($label, 'UTF-8') === $label;
    }

    /**
     * @param array<string, int> $variantCounts raw label => počet událostí
     */
    public function pickDisplayLabelFromVariants(array $variantCounts): string
    {
        if ($variantCounts === []) {
            return '';
        }
        \arsort($variantCounts, \SORT_NUMERIC);
        $max = (int) \reset($variantCounts);
        $best = '';
        foreach ($variantCounts as $label => $count) {
            if ((int) $count < $max) {
                break;
            }
            $best = '' === $best ? $label : $this->preferDisplayLabel($best, $label);
        }

        return $best;
    }

    /**
     * @return array<string, string>
     */
    public function getNormalizedToCanonicalMap(): array
    {
        return $this->aliases->getNormalizedToCanonicalMap();
    }

    public function merge(string $aliasLabel, string $canonicalLabel): void
    {
        $this->upsertAlias($aliasLabel, $canonicalLabel);
        $this->em->flush();
    }

    /**
     * @param list<string> $aliasLabels zápisy z deníku, které se v reportu přejmenují na kanon
     */
    public function mergeBulk(string $canonicalLabel, array $aliasLabels): void
    {
        $canonicalLabel = \trim($canonicalLabel);
        if ('' === $canonicalLabel) {
            throw new \InvalidArgumentException('Vyberte kanonický název projektu.');
        }
        if ($this->isReservedReportLabel($canonicalLabel)) {
            throw new \InvalidArgumentException('Systémové položky (odpis, předání) nelze slučovat.');
        }

        $seen = [];
        $canonicalNorm = $this->normalize($canonicalLabel);
        $count = 0;
        foreach ($aliasLabels as $aliasLabel) {
            $aliasLabel = \trim($aliasLabel);
            if ('' === $aliasLabel) {
                continue;
            }
            $norm = $this->normalize($aliasLabel);
            if ('' === $norm || $norm === $canonicalNorm) {
                continue;
            }
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $this->upsertAlias($aliasLabel, $canonicalLabel);
            ++$count;
        }
        if (0 === $count) {
            throw new \InvalidArgumentException('Žádný zápis k sloučení — vyberte alespoň dva různé projekty.');
        }
        $this->em->flush();
    }

    private function upsertAlias(string $aliasLabel, string $canonicalLabel): void
    {
        $aliasLabel = \trim($aliasLabel);
        $canonicalLabel = \trim($canonicalLabel);
        if ('' === $aliasLabel || '' === $canonicalLabel) {
            throw new \InvalidArgumentException('Vyplňte oba názvy zakázky.');
        }
        if ($this->isReservedReportLabel($aliasLabel) || $this->isReservedReportLabel($canonicalLabel)) {
            throw new \InvalidArgumentException('Systémové položky (odpis, předání) nelze slučovat.');
        }
        if ($this->normalize($aliasLabel) === $this->normalize($canonicalLabel)) {
            throw new \InvalidArgumentException('Názvy jsou po normalizaci stejné — sloučení není potřeba.');
        }

        $norm = $this->normalize($aliasLabel);
        $row = $this->aliases->findOneByAliasNormalized($norm);
        if (null === $row) {
            $row = new ProjectReportAlias();
            $row->setAliasNormalized($norm);
            $this->em->persist($row);
        }
        $row->setAliasLabel($aliasLabel);
        $row->setCanonicalLabel($canonicalLabel);
    }

    public function remove(int $id): void
    {
        $row = $this->aliases->find($id);
        if (null === $row) {
            throw new \InvalidArgumentException('Pravidlo sloučení nebylo nalezeno.');
        }
        $this->em->remove($row);
        $this->em->flush();
    }
}
