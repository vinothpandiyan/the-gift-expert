<?php

namespace App\Console\Commands;

use App\Actions\RecipientEligibility\EvaluateRecipientEligibilityReviewAction;
use App\RecipientEligibility\RecipientEligibilityConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class RecipientEligibilityReviewGateCommand extends Command
{
    protected $signature = 'recipient-eligibility:review-gate {file : Reviewed samples CSV (review column: keep|borderline|reject)}';

    protected $description = 'Score a reviewed inferred-product sample and record which relationship profiles clear the suitability threshold. Does not enable inference.';

    public function handle(EvaluateRecipientEligibilityReviewAction $evaluate): int
    {
        try {
            $results = $evaluate->execute((string) $this->argument('file'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $path = (string) config('recipient_eligibility.review_gate.file');
        $existing = File::exists($path) ? json_decode((string) File::get($path), true) : [];
        $relationships = array_merge((array) ($existing['relationships'] ?? []), array_intersect_key($results, array_flip(RecipientEligibilityConfig::waveOneSlugs())));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['updated_at' => now()->toIso8601String(), 'relationships' => $relationships], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($results as $slug => $row) {
            $this->line(sprintf(
                '%-10s %s  suitability %.1f%% (%d keep / %d borderline / %d reject / %d unreviewed)',
                $slug,
                $row['passed'] ? 'PASS' : 'FAIL',
                $row['suitability'] * 100,
                $row['keep'],
                $row['borderline'],
                $row['reject'],
                $row['unreviewed'],
            ));
        }

        $this->info("Gate recorded in {$path}. Passing the gate does not enable inference; the feature flags still must be turned on separately.");

        return collect($results)->every(fn (array $row): bool => $row['passed']) ? self::SUCCESS : self::FAILURE;
    }
}
