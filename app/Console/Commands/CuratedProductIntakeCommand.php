<?php

namespace App\Console\Commands;

use App\Actions\CuratedCatalog\AssertCuratedProductIntakeBatchIsImportableAction;
use App\Actions\CuratedCatalog\ProcessCuratedProductIntakeBatchAction;
use App\CuratedCatalog\CuratedProductIntakeBatchReport;
use App\CuratedCatalog\CuratedProductIntakeHardStopException;
use App\CuratedCatalog\CuratedProductIntakeParseException;
use App\CuratedCatalog\UnmappedCatalogSourceListsException;
use App\Models\Relationship;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class CuratedProductIntakeCommand extends Command
{
    protected $signature = 'catalog:curated-intake
        {path : JSON file or directory of wishlist JSON files}
        {--dry-run : Parse, merge, and report without writing}
        {--defer-classification : Create/update commercial identity without AI classification}
        {--allow-unknown-source-lists : Commit unmapped lists as kind=unknown}';

    protected $description = 'Ingest curated merchant wishlist JSON with merge, provenance, and optional dry-run.';

    public function handle(
        ProcessCuratedProductIntakeBatchAction $batch,
        AssertCuratedProductIntakeBatchIsImportableAction $assertImportable,
    ): int {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $allowUnknown = (bool) $this->option('allow-unknown-source-lists');

        try {
            $report = $batch->dryRun($path);

            if ($dryRun) {
                $this->printReport($report);
                $hardStops = $assertImportable->reasons($report, $allowUnknown);
                $this->printHardStops($hardStops);
                $this->info('Dry run completed. No catalog rows, provenance, or intake runs were written.');

                return $hardStops === [] ? self::SUCCESS : self::FAILURE;
            }

            $this->printMappings($report);
            $assertImportable->execute($report, $allowUnknown);

            $result = $batch->commit(
                $path,
                (bool) $this->option('defer-classification'),
                $allowUnknown,
            );
        } catch (UnmappedCatalogSourceListsException $exception) {
            $this->error($exception->getMessage());
            $this->line('Unmapped source lists (needs_source_mapping):');

            foreach ($exception->unmappedLists as $list) {
                $this->line(sprintf(
                    '- %s | id=%s | url=%s | products=%d',
                    $list['name'] ?? '(unnamed)',
                    $list['external_list_id'] ?? 'null',
                    $list['url'] ?? 'null',
                    (int) ($list['product_count'] ?? 0),
                ));
            }

            $this->comment('Resolve mappings in config/curated_catalog.php before commit.');
            $this->comment('Pass --allow-unknown-source-lists to ingest unmapped provenance intentionally.');

            return self::FAILURE;
        } catch (CuratedProductIntakeHardStopException $exception) {
            $this->error($exception->getMessage());
            $this->printHardStops($exception->reasons);

            return self::FAILURE;
        } catch (InvalidArgumentException|CuratedProductIntakeParseException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Intake run {$result->runId} is {$result->status}.");
        $this->line('Record this ID for classification targeting.');
        $this->newLine();
        $this->line("New products: {$result->itemsCreated}");
        $this->line("Existing products: {$result->itemsUpdated}");
        $this->line("Skipped: {$result->itemsSkipped}");
        $this->line("Failed: {$result->itemsFailed}");
        $this->newLine();
        $this->comment("Next: php artisan catalog:classify-curated --intake-run={$result->runId} --dry-run");

        return $result->itemsFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printReport(CuratedProductIntakeBatchReport $report): void
    {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Wishlist files', count($report->files)],
                ['Wishlist/list count', $report->wishlistCount],
                ['Raw occurrences', $report->rawOccurrences],
                ['Unique merchant products (ASINs)', $report->uniqueProducts],
                ['Merged duplicate occurrences', $report->mergedOccurrences],
                ['Products appearing in >1 list', $report->multiListProducts],
                ['New products', $report->newProducts],
                ['Existing products', $report->existingProducts],
                ['Recipient-hint lists', $report->recipientHintLists],
                ['Unclassified lists', $report->unclassifiedLists],
                ['Quarterly archive lists', $report->quarterlyArchiveLists],
                ['Unknown/unmapped lists', $report->unknownLists],
                ['Malformed/missing external IDs', $report->malformedExternalIds],
                ['Commercial-field conflicts', $report->commercialConflicts],
                ['Title conflicts', $report->commercialConflictFieldCounts['title'] ?? 0],
                ['Price conflicts', $report->commercialConflictFieldCounts['price'] ?? 0],
                ['Availability conflicts', $report->commercialConflictFieldCounts['availability'] ?? 0],
                ['Image conflicts', $report->commercialConflictFieldCounts['image'] ?? 0],
                ['Multi-recipient products', $report->multiRecipientProducts],
            ],
        );

        $this->printMappings($report);

        if ($report->productsBySourceList !== []) {
            $this->newLine();
            $this->info('Products by source list');
            $rows = [];

            foreach ($report->productsBySourceList as $name => $count) {
                $rows[] = [$name, $count];
            }

            $this->table(['Source list', 'Products'], $rows);
        }

        if ($report->productsByRelationshipHint !== []) {
            $this->newLine();
            $this->info('Recipient hint distribution');
            $rows = [];

            foreach ($report->productsByRelationshipHint as $name => $count) {
                $rows[] = [$name, $count];
            }

            $this->table(['Relationship', 'Products'], $rows);
        }

        if ($report->unmappedLists !== []) {
            $this->newLine();
            $this->warn('Unknown/unmapped lists (needs_source_mapping)');

            foreach ($report->unmappedLists as $list) {
                $this->line(sprintf(
                    '- %s | id=%s | url=%s | products=%d',
                    $list['name'] ?? '(unnamed)',
                    $list['external_list_id'] ?? 'null',
                    $list['url'] ?? 'null',
                    (int) ($list['product_count'] ?? 0),
                ));
            }
        }
    }

    private function printMappings(CuratedProductIntakeBatchReport $report): void
    {
        if ($report->sourceLists === []) {
            return;
        }

        $this->newLine();
        $this->info('Resolved source-list mappings');
        $this->comment('This is the last sanity check before ingestion. Mappings come from config, not free-text inference.');
        $this->newLine();

        $relationshipNames = Relationship::query()
            ->where('is_active', true)
            ->pluck('name', 'slug');

        foreach ($report->sourceLists as $list) {
            $name = $list['name'] ?? '(unnamed)';
            $kind = $list['kind'] ?? 'unknown';
            $this->line((string) $name);
            $this->line('→ '.$kind);

            if ($kind === 'recipient_hint' && is_string($list['relationship'] ?? null) && $list['relationship'] !== '') {
                $slug = $list['relationship'];
                $label = $relationshipNames[$slug] ?? $slug;
                $this->line('→ relationship: '.$label);
            }

            $this->newLine();
        }
    }

    /**
     * @param  list<array{code: string, message: string, details: list<array<string, mixed>>}>  $reasons
     */
    private function printHardStops(array $reasons): void
    {
        if ($reasons === []) {
            return;
        }

        $this->newLine();
        $this->error('Hard-stop conditions (commit is blocked until these are resolved):');

        foreach ($reasons as $reason) {
            $this->line('- '.$reason['code'].': '.$reason['message']);

            foreach ($reason['details'] as $detail) {
                $this->line('    '.json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }
    }
}
