<?php

namespace Database\Factories;

use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\CatalogCandidateStatus;
use App\Models\CatalogCandidate;
use App\Support\CatalogCandidateTitleFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogCandidate>
 */
class CatalogCandidateFactory extends Factory
{
    protected $model = CatalogCandidate::class;

    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'title' => $title,
            'title_fingerprint' => CatalogCandidateTitleFingerprint::from($title),
            'status' => CatalogCandidateStatus::Discovered,
            'priority' => CatalogCandidatePriority::Normal,
            'source_type' => CatalogCandidateSourceType::Manual,
            'discovered_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CatalogCandidate $candidate): void {
            if (filled($candidate->title)) {
                $candidate->title_fingerprint = CatalogCandidateTitleFingerprint::from($candidate->title);
            }
        });
    }
}
