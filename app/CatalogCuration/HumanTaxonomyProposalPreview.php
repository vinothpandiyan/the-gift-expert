<?php

namespace App\CatalogCuration;

readonly class HumanTaxonomyProposalPreview
{
    /**
     * @param  list<array{dimension: string, changes: list<string>}>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $hasChanges,
    ) {}
}
