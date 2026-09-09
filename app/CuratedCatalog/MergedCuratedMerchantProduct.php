<?php

namespace App\CuratedCatalog;

readonly class MergedCuratedMerchantProduct
{
    /**
     * @param  list<CuratedMerchantProductInput>  $occurrences
     * @param  list<CuratedSourceListContext>  $sourceLists
     * @param  list<string>  $conflicts
     */
    public function __construct(
        public string $merchantSlug,
        public string $externalProductId,
        public CuratedMerchantProductInput $input,
        public array $occurrences,
        public array $sourceLists,
        public array $conflicts,
    ) {}

    public function occurrenceCount(): int
    {
        return count($this->occurrences);
    }

    public function mergedOccurrenceCount(): int
    {
        return max($this->occurrenceCount() - 1, 0);
    }

    public function sourceListCount(): int
    {
        return count($this->sourceLists);
    }

    public function isMultiList(): bool
    {
        return $this->sourceListCount() > 1;
    }

    /**
     * @return list<string>
     */
    public function sourceListNames(): array
    {
        $names = [];

        foreach ($this->sourceLists as $sourceList) {
            $name = $sourceList->displayName();

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
