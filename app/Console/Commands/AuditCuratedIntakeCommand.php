<?php

namespace App\Console\Commands;

use App\Actions\CuratedCatalog\AuditCuratedIntakeRunAction;
use App\CuratedCatalog\CuratedIntakeAuditReport;
use App\Enums\TaxonomyClassificationStatus;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AuditCuratedIntakeCommand extends Command
{
    protected $signature = 'catalog:curated-audit
        {--intake-run= : Curated intake run ID to audit}';

    protected $description = 'Report provenance, classification, and taxonomy integrity for a curated intake run.';

    public function handle(AuditCuratedIntakeRunAction $audit): int
    {
        $intakeRunId = $this->option('intake-run');

        if (! is_string($intakeRunId) || $intakeRunId === '' || ! ctype_digit($intakeRunId)) {
            $this->error('Pass --intake-run=<id> from catalog:curated-intake.');

            return self::FAILURE;
        }

        try {
            $report = $audit->execute((int) $intakeRunId);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report);

        return $this->hasIntegrityFailures($report) ? self::FAILURE : self::SUCCESS;
    }

    private function printReport(CuratedIntakeAuditReport $report): void
    {
        $this->info("Curated intake audit for run {$report->intakeRunId}");
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Unique products', $report->uniqueProducts],
                ['New products', $report->newProducts],
                ['Existing products', $report->existingProducts],
                ['Multi-list products', $report->multiListProducts],
                ['Provenance rows', $report->provenanceRows],
                ['Wrong-merchant provenance', $report->wrongMerchantProvenance],
                ['none', $report->classificationCounts[TaxonomyClassificationStatus::None->value] ?? 0],
                ['ai_accepted', $report->classificationCounts[TaxonomyClassificationStatus::AiAccepted->value] ?? 0],
                ['review', $report->classificationCounts[TaxonomyClassificationStatus::Review->value] ?? 0],
                ['failed', $report->classificationCounts[TaxonomyClassificationStatus::Failed->value] ?? 0],
                ['human_approved', $report->classificationCounts[TaxonomyClassificationStatus::HumanApproved->value] ?? 0],
                ['human_overridden', $report->classificationCounts[TaxonomyClassificationStatus::HumanOverridden->value] ?? 0],
                ['draft', $report->publicationCounts['draft'] ?? 0],
                ['published', $report->publicationCounts['published'] ?? 0],
                ['archived', $report->publicationCounts['archived'] ?? 0],
                ['Title conflicts', $report->commercialConflictFieldCounts['title'] ?? 0],
                ['Price conflicts', $report->commercialConflictFieldCounts['price'] ?? 0],
                ['Availability conflicts', $report->commercialConflictFieldCounts['availability'] ?? 0],
                ['Image conflicts', $report->commercialConflictFieldCounts['image'] ?? 0],
                ['Missing primary category', count($report->missingPrimaryCategory)],
                ['Multiple primaries', count($report->multiplePrimaryCategories)],
                ['Missing ancestors', count($report->missingAncestors)],
                ['Semantic conflicts', count($report->semanticConflicts)],
                ['Provenance mismatches', count($report->provenanceIssues)],
                ['Residual none', count($report->residualNone)],
            ],
        );

        $this->printIssueList('Child primary Category missing active ancestor', $report->missingAncestors, ['child', 'expected_ancestor']);
        $this->printIssueList('Multiple primary Categories', $report->multiplePrimaryCategories);
        $this->printIssueList('No primary Category on accepted/human products', $report->missingPrimaryCategory);
        $this->printIssueList('Applied semantic conflicts', $report->semanticConflicts, ['left', 'right']);
        $this->printIssueList('Provenance mismatches', $report->provenanceIssues);
        $this->printIssueList('Inactive recipient-hint Relationships', $report->inactiveHintRelationships, ['relationship']);
        $this->printIssueList('Quarterly/unclassified lists that stored taxonomy hints', $report->taxonomyFromNonHintLists);

        if ($report->residualNone !== []) {
            $this->newLine();
            $this->warn('Residual classification status=none (must be explained, not silent):');

            foreach ($report->residualNone as $row) {
                $this->line(sprintf(
                    '- product=%d asin=%s explanation=%s',
                    $row['product_id'],
                    $row['external_id'] ?? '—',
                    $row['explanation'] ?? 'unknown',
                ));
            }
        }

        if ($report->reviewPriority !== []) {
            $this->newLine();
            $this->info('Review priority (FAILED is tab 1 in Filament; these are Needs Review):');

            foreach ($report->reviewPriority as $row) {
                $this->line(sprintf(
                    '- priority=%d product=%d reasons=%s',
                    $row['priority'],
                    $row['product_id'],
                    implode(',', $row['reasons'] ?? []) ?: '—',
                ));
            }
        }

        if ($report->spotCheck !== []) {
            $this->newLine();
            $this->info('Spot-check sample of AI Accepted products:');
            $this->comment('Include multi-list, child Category, Personalized/Hampers/Experience if present, cheap and expensive.');

            foreach ($report->spotCheck as $row) {
                $this->line(sprintf(
                    '- product=%d asin=%s price=%s multi_list=%s',
                    $row['product_id'],
                    $row['external_id'] ?? '—',
                    $row['price_amount'] ?? '—',
                    ! empty($row['multi_list']) ? 'yes' : 'no',
                ));
            }
        }

        $this->newLine();
        $this->comment('Review queue: Admin → Catalog → Gifts');
        $this->line('  Failed: /admin/gifts?activeTab=failed');
        $this->line('  Needs Review: /admin/gifts?activeTab=review');
        $this->line('  AI Accepted: /admin/gifts?activeTab=ai_accepted');
        $this->comment('Do not bulk-approve REVIEW. Do not auto-publish. Publication remains manual.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $extraKeys
     */
    private function printIssueList(string $heading, array $rows, array $extraKeys = []): void
    {
        $this->newLine();

        if ($rows === []) {
            $this->info("{$heading}: 0");

            return;
        }

        $this->warn("{$heading}: ".count($rows));

        foreach ($rows as $row) {
            $extra = [];

            foreach ($extraKeys as $key) {
                if (isset($row[$key])) {
                    $extra[] = $key.'='.(is_scalar($row[$key]) ? (string) $row[$key] : json_encode($row[$key]));
                }
            }

            $this->line(sprintf(
                '- product=%d asin=%s%s',
                $row['product_id'] ?? 0,
                $row['external_id'] ?? '—',
                $extra === [] ? '' : ' '.implode(' ', $extra),
            ));
        }
    }

    private function hasIntegrityFailures(CuratedIntakeAuditReport $report): bool
    {
        return $report->wrongMerchantProvenance > 0
            || $report->missingPrimaryCategory !== []
            || $report->multiplePrimaryCategories !== []
            || $report->missingAncestors !== []
            || $report->semanticConflicts !== []
            || $report->provenanceIssues !== []
            || $report->inactiveHintRelationships !== []
            || $report->taxonomyFromNonHintLists !== [];
    }
}
