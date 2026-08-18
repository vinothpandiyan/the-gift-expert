<?php

namespace Database\Factories;

use App\Enums\CatalogCandidateSourceType;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogCandidateEvidence>
 */
class CatalogCandidateEvidenceFactory extends Factory
{
    protected $model = CatalogCandidateEvidence::class;

    public function definition(): array
    {
        return [
            'catalog_candidate_id' => CatalogCandidate::factory(),
            'source_type' => CatalogCandidateSourceType::Web,
            'source_name' => fake()->optional()->company(),
            'source_url' => fake()->unique()->url(),
            'summary' => fake()->optional()->sentence(),
            'observed_at' => now(),
            'metadata' => null,
        ];
    }
}
