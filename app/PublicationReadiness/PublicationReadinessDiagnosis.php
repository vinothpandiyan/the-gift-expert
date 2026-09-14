<?php

namespace App\PublicationReadiness;

use App\Enums\PublicationReadinessDiagnosisCode;
use App\Enums\PublicationReadinessRemediation;

readonly class PublicationReadinessDiagnosis
{
    /**
     * @param  list<string>  $blockers
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>|null  $suggestedTaxonomy
     */
    public function __construct(
        public int $productId,
        public string $title,
        public array $blockers,
        public PublicationReadinessDiagnosisCode $code,
        public PublicationReadinessRemediation $remediation,
        public string $reason,
        public array $before,
        public ?array $suggestedTaxonomy = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'title' => $this->title,
            'blockers' => $this->blockers,
            'diagnosis' => $this->code->letter(),
            'diagnosis_code' => $this->code->value,
            'approved_remediation' => $this->remediation->value,
            'reason' => $this->reason,
            'before' => $this->before,
            'suggested_taxonomy' => $this->suggestedTaxonomy,
        ];
    }
}
