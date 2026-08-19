<?php

namespace Database\Factories;

use App\Enums\CatalogCandidateSourcingRunStatus;
use App\Models\CatalogCandidateSourcingRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogCandidateSourcingRun>
 */
class CatalogCandidateSourcingRunFactory extends Factory
{
    protected $model = CatalogCandidateSourcingRun::class;

    public function definition(): array
    {
        return [
            'status' => CatalogCandidateSourcingRunStatus::Running,
            'market' => 'IN',
            'started_at' => now(),
            'items_total' => 0,
            'items_succeeded' => 0,
            'items_skipped' => 0,
            'items_failed' => 0,
        ];
    }
}
