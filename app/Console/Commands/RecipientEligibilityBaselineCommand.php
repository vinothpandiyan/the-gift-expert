<?php

namespace App\Console\Commands;

use App\Actions\RecipientEligibility\BuildRecipientEligibilityBaselineAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RecipientEligibilityBaselineCommand extends Command
{
    protected $signature = 'recipient-eligibility:baseline
        {--output= : Write the baseline JSON to this path (default storage/app/audit/baseline-<date>.json)}
        {--compare= : Re-run and diff against this baseline file instead of writing a new one}';

    protected $description = 'Capture or compare a read-only recipient-eligibility baseline (catalog metrics, URL matrix, Finder replays).';

    public function handle(BuildRecipientEligibilityBaselineAction $baseline): int
    {
        $this->makeSessionReadOnly();

        $compare = $this->option('compare');

        if (is_string($compare) && $compare !== '') {
            return $this->compareWith($baseline, $compare);
        }

        $path = $this->option('output');
        $path = is_string($path) && $path !== ''
            ? $path
            : storage_path('app/audit/baseline-'.now()->format('Y-m-d').'.json');

        $result = $baseline->execute();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("Baseline written to {$path}");
        $this->line('Published: '.$result['metrics']['published'].' | URL states: '.count($result['url_matrix']).' | Finder replays: '.count($result['finder']));

        return self::SUCCESS;
    }

    private function compareWith(BuildRecipientEligibilityBaselineAction $baseline, string $path): int
    {
        if (! File::exists($path)) {
            $this->error("Baseline file not found: {$path}");

            return self::FAILURE;
        }

        $before = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $differences = $baseline->diff($before, $baseline->execute());

        if ($differences === []) {
            $this->info('Parity: identical to the baseline.');

            return self::SUCCESS;
        }

        foreach ($differences as $difference) {
            $this->line($difference);
        }

        $this->warn(count($differences).' difference(s).');

        return self::FAILURE;
    }

    /**
     * Guard against accidental writes: the audit only reads the catalog.
     */
    private function makeSessionReadOnly(): void
    {
        if (app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('SET SESSION TRANSACTION READ ONLY');
    }
}
