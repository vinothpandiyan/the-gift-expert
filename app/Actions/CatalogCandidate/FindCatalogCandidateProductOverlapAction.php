<?php

namespace App\Actions\CatalogCandidate;

use App\Models\CatalogCandidate;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

class FindCatalogCandidateProductOverlapAction
{
    /**
     * @return Collection<int, Product>
     */
    public function execute(CatalogCandidate $candidate): Collection
    {
        $title = mb_strtolower(trim($candidate->title), 'UTF-8');

        return Product::query()
            ->whereRaw('LOWER(name) = ?', [$title])
            ->get();
    }
}
