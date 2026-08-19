<?php

namespace Database\Factories;

use App\Enums\CatalogCandidateSourcingItemStatus;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\CatalogCandidateSourcingRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogCandidateSourcingItem>
 */
class CatalogCandidateSourcingItemFactory extends Factory
{
    protected $model = CatalogCandidateSourcingItem::class;

    public function definition(): array
    {
        return [
            'catalog_candidate_sourcing_run_id' => CatalogCandidateSourcingRun::factory(),
            'catalog_candidate_id' => CatalogCandidate::factory(),
            'status' => CatalogCandidateSourcingItemStatus::Succeeded,
        ];
    }
}
