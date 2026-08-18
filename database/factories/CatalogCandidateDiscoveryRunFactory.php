<?php

namespace Database\Factories;

use App\Enums\CatalogCandidateDiscoveryRunStatus;
use App\Models\CatalogCandidateDiscoveryRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogCandidateDiscoveryRun>
 */
class CatalogCandidateDiscoveryRunFactory extends Factory
{
    protected $model = CatalogCandidateDiscoveryRun::class;

    public function definition(): array
    {
        return [
            'provider_key' => 'fake',
            'brief' => 'thoughtful gifts',
            'market' => 'IN',
            'max_candidates' => 10,
            'freshness_days' => 30,
            'status' => CatalogCandidateDiscoveryRunStatus::Running,
            'candidates_proposed' => 0,
            'started_at' => now(),
        ];
    }
}
