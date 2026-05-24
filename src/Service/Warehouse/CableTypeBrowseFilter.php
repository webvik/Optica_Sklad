<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

/**
 * Filtr „Typ kabelu“ v přehledu skladu (včetně cívek bez doplněného typu).
 */
final readonly class CableTypeBrowseFilter
{
    /**
     * @param list<int> $ids vybrané ID z katalogu (prázdné = žádné omezení podle ID)
     */
    public function __construct(
        public array $ids = [],
        public bool $includeUnset = false,
        public bool $onlyWithAssignedType = false,
    ) {
    }

    public function restrictsCableDimension(): bool
    {
        return $this->ids !== [] || $this->includeUnset || $this->onlyWithAssignedType;
    }
}
