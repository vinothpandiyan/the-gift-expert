<?php

namespace App\CatalogCuration;

use App\Enums\P0IntegrityDiagnosis;

readonly class P0IntegrityDiagnosisResult
{
    /**
     * @param  list<string>  $signals
     */
    public function __construct(
        public P0IntegrityDiagnosis $diagnosis,
        public string $summary,
        public array $signals,
    ) {}
}
