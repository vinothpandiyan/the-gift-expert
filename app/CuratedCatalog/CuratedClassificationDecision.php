<?php

namespace App\CuratedCatalog;

use App\Enums\TaxonomyClassificationStatus;

readonly class CuratedClassificationDecision
{
    /**
     * @param  list<string>  $warnings
     * @param  list<string>  $reviewReasons
     */
    public function __construct(
        public TaxonomyClassificationStatus $status,
        public ?CuratedClassificationProposal $proposal,
        public array $warnings,
        public array $reviewReasons,
        public CuratedTaxonomyGap $taxonomyGap,
    ) {}
}
