<?php

namespace App\GapSourcing;

use App\Enums\GapSourcingKind;

readonly class GapSourcingGap
{
    /**
     * @param  list<string>  $names
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $priority,
        public string $dimension,
        public array $names,
        public int $publishedCount,
        public int $keepFamilyDraftCount,
        public int $otherDraftCount,
        public GapSourcingKind $kind,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'priority' => $this->priority,
            'dimension' => $this->dimension,
            'names' => $this->names,
            'published_count' => $this->publishedCount,
            'keep_family_draft_count' => $this->keepFamilyDraftCount,
            'other_draft_count' => $this->otherDraftCount,
            'kind' => $this->kind->value,
        ];
    }
}
