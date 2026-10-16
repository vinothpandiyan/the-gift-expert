<?php

namespace App\Console\Commands;

use App\Actions\RecipientEligibility\AuditRecipientEligibilityAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RecipientEligibilityAuditCommand extends Command
{
    protected $signature = 'recipient-eligibility:audit
        {--dir= : Output directory (default storage/app/audit)}
        {--name= : Report name stem (default shadow-audit-<date>)}
        {--skip-pool : Skip the candidate-pool benchmark}';

    protected $description = 'Read-only shadow audit: funnel, contradictions, strict vs eligible URL matrix, review samples and pool benchmark. Writes JSON, Markdown and CSV samples.';

    public function handle(AuditRecipientEligibilityAction $audit): int
    {
        $this->makeSessionReadOnly();

        $dir = (string) ($this->option('dir') ?: storage_path('app/audit'));
        $stem = (string) ($this->option('name') ?: 'shadow-audit-'.now()->format('Y-m-d'));

        File::ensureDirectoryExists($dir);

        $report = $audit->execute(! $this->option('skip-pool'));
        $samples = $audit->samples();

        File::put("{$dir}/{$stem}.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::put("{$dir}/{$stem}.md", $audit->toMarkdown($report));
        File::put("{$dir}/{$stem}-samples.json", json_encode($samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->writeSamplesCsv("{$dir}/{$stem}-samples.csv", $samples);

        $this->info("Report written to {$dir}/{$stem}.md (+ .json, -samples.csv, -samples.json)");

        foreach ($report['funnel'] as $slug => $row) {
            $this->line(sprintf('%-10s explicit %-4d inferred %-4d', $slug, $row['explicit'], $row['inferred']));
        }

        $inconsistent = collect($report['matrix'])
            ->filter(fn (array $row): bool => collect($row['consistency'])->contains(false))
            ->keys();

        $changedControls = collect($report['matrix'])
            ->filter(fn (array $row): bool => $row['control'] && (! $row['listing_unchanged'] || array_diff($row['facet_dimensions_changed'], ['relationship']) !== []))
            ->keys();

        if ($inconsistent->isNotEmpty()) {
            $this->error('Inconsistent states: '.$inconsistent->implode(', '));
        }

        if ($changedControls->isNotEmpty()) {
            $this->error('Control states changed: '.$changedControls->implode(', '));
        }

        return $inconsistent->isEmpty() && $changedControls->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $samples
     */
    private function writeSamplesCsv(string $path, array $samples): void
    {
        $handle = fopen($path, 'w');
        $header = ['relationship', 'product_id', 'name', 'primary_category', 'genders', 'recipient_types', 'occasions', 'interests', 'review', 'notes'];
        fputcsv($handle, $header, escape: '\\');

        foreach ($samples as $rows) {
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn (string $key) => $row[$key], $header), escape: '\\');
            }
        }

        fclose($handle);
    }

    private function makeSessionReadOnly(): void
    {
        if (app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('SET SESSION TRANSACTION READ ONLY');
    }
}
