<?php

namespace App\Console\Commands;

use App\Actions\LaunchPublication\BuildLaunchGapBriefAction;
use App\Actions\LaunchPublication\BuildLaunchPublicationPreviewAction;
use App\Actions\LaunchPublication\ExecuteLaunchPublicationAction;
use Illuminate\Console\Command;

class LaunchCatalogPublicationCommand extends Command
{
    protected $signature = 'catalog:launch-publication
        {--execute : Publish the proposed launch cohort through PublishProductAction}
        {--json : Output machine-readable JSON}';

    protected $description = 'Preview or execute the Phase 22D.1 curated launch publication cohort.';

    public function handle(
        BuildLaunchPublicationPreviewAction $buildPreview,
        ExecuteLaunchPublicationAction $executeLaunch,
        BuildLaunchGapBriefAction $buildGapBrief,
    ): int {
        $preview = $buildPreview->execute();
        $internal = $preview['_internal'];
        unset($preview['_internal']);

        if (! $this->option('execute')) {
            $preview['gap_brief'] = $buildGapBrief->execute(
                $internal['readiness'],
                $preview['coverage_preview'],
            );

            if ($this->option('json')) {
                $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $this->printPreview($preview);

            return self::SUCCESS;
        }

        $result = $executeLaunch->execute(
            $internal['cohort']['product_ids'],
            $internal['readiness'],
        );

        $payload = [
            'preview' => $preview,
            'execution' => $result,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $result['reconciliation']['unexplained'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->printPreview($preview);
        $this->newLine();
        $this->printExecution($result);

        return $result['reconciliation']['unexplained'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function printPreview(array $preview): void
    {
        $counts = $preview['catalog_counts'];
        $this->info('Launch publication preview');
        $this->newLine();
        $this->line('Catalog fingerprint hash: '.$preview['fingerprint_hash']);
        $this->line(sprintf(
            'Draft: %d  Published: %d  Archived: %d  Human decisions: %d  Audit runs/rows: %d / %d',
            $counts['draft'],
            $counts['published'],
            $counts['archived'],
            $counts['human_decisions'],
            $counts['audit_runs'],
            $counts['audit_rows'],
        ));

        $this->newLine();
        $this->line('Already published: '.$preview['published']['count']);
        if ($preview['published']['anomalies'] !== []) {
            $this->warn('Published anomalies: '.count($preview['published']['anomalies']));
            foreach ($preview['published']['anomalies'] as $anomaly) {
                $this->line(sprintf(
                    '  #%d %s (%s)',
                    $anomaly['product_id'],
                    $anomaly['title'],
                    implode(', ', $anomaly['flags'] ?? []),
                ));
            }
        }

        $this->newLine();
        $this->line('FEATURE products');
        foreach ($preview['feature_products'] as $row) {
            $this->line(sprintf(
                '  #%d %s  %s  %s',
                $row['product_id'],
                $row['title'],
                $row['status'],
                $row['readiness'],
            ));
        }

        $readiness = $preview['readiness'];
        $this->newLine();
        $this->line(sprintf(
            'Retained draft: %d  Publish-ready: %d  Blocked: %d',
            $readiness['retained_draft'],
            $readiness['publish_ready'],
            $readiness['blocked'],
        ));
        foreach ($readiness['blocker_counts'] as $code => $count) {
            $this->line(sprintf('  %-36s %d', $code, $count));
        }

        $this->newLine();
        $this->line('Proposed cohort: '.$preview['cohort']['count']);
        $this->table(
            ['Product', 'Decision', 'Role', 'Price', 'Gift', 'CV', 'Ready'],
            collect($preview['cohort']['rows'])->map(fn (array $row): array => [
                '#'.$row['product_id'].' '.$row['title'],
                $row['human_decision'],
                $row['human_merchandising_role'] ?? '—',
                $row['price_amount'] ?? '—',
                $row['gift_score'] ?? '—',
                $row['catalog_value'] ?? '—',
                $row['readiness'],
            ])->all(),
        );

        if ($preview['cohort']['concept_warnings'] !== []) {
            $this->warn('Concept concentration warnings:');
            foreach ($preview['cohort']['concept_warnings'] as $warning) {
                $this->line(sprintf(
                    '  %s (%d): %s',
                    $warning['concept_label'] ?: $warning['concept_key'],
                    $warning['count'],
                    implode(', ', $warning['product_ids']),
                ));
            }
        }

        $this->newLine();
        $this->line('Coverage preview (already published + proposed cohort)');
        $this->printCoverage($preview['coverage_preview']);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printExecution(array $result): void
    {
        $tally = $result['tally'];
        $this->info('Publication results');
        $this->line(sprintf(
            'Newly published: %d  Skipped: %d  Failed: %d',
            $tally['published'],
            $tally['skipped'],
            $tally['failed'],
        ));
        $this->line(sprintf(
            'Final published: %d  Final draft: %d  Final archived: %d',
            $result['catalog_counts']['published'],
            $result['catalog_counts']['draft'],
            $result['catalog_counts']['archived'],
        ));

        $this->newLine();
        $this->line('Mutation manifest');
        foreach ($result['attempts'] as $attempt) {
            $this->line(sprintf(
                '  #%d %-10s %s',
                $attempt['product_id'],
                $attempt['result'],
                $attempt['reason'],
            ));
        }

        if ($result['reconciliation']['unexplained'] !== []) {
            $this->error('Unexplained fingerprint mutations:');
            foreach ($result['reconciliation']['unexplained'] as $row) {
                $this->line('  '.json_encode($row));
            }
        } else {
            $this->info('Fingerprint reconciliation: all mutations explained by the publication manifest.');
        }

        $this->newLine();
        $this->printCoverage($result['coverage']);
    }

    /**
     * @param  array<string, mixed>  $coverage
     */
    private function printCoverage(array $coverage): void
    {
        foreach (['budget', 'relationships', 'occasions', 'interests', 'gift_types', 'gift_intents'] as $dimension) {
            $this->line(str($dimension)->replace('_', ' ')->headline());
            foreach ($coverage[$dimension] ?? [] as $row) {
                $this->line(sprintf(
                    '  %-28s %d',
                    $row['name'] ?? $row['slug'] ?? $row['value'],
                    $row['count'],
                ));
            }
        }

        $holes = collect($coverage['holes'] ?? [])->whereIn('level', ['critical', 'high']);
        if ($holes->isNotEmpty()) {
            $this->newLine();
            $this->warn('Launch holes (critical/high)');
            foreach ($holes as $hole) {
                $this->line(sprintf(
                    '  %-10s %-18s %s (%d)',
                    $hole['level'],
                    $hole['dimension'],
                    $hole['label'],
                    $hole['count'],
                ));
            }
        }
    }
}
