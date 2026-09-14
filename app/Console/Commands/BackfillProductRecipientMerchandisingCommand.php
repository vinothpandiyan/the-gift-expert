<?php

namespace App\Console\Commands;

use App\Actions\RecipientMerchandising\BackfillProductRecipientMerchandisingAction;
use App\RecipientMerchandising\RecipientMerchandisingBackfillAssignment;
use App\RecipientMerchandising\RecipientMerchandisingBackfillResult;
use Illuminate\Console\Command;

class BackfillProductRecipientMerchandisingCommand extends Command
{
    protected $signature = 'catalog:backfill-recipient-merchandising
        {--dry-run : Report proposed assignments without writing}
        {--limit= : Maximum products to examine}
        {--product=* : Limit to one or more product IDs}
        {--route-ambiguous-to-review : On commit, mark ambiguous gender cases as taxonomy review}
        {--samples=5 : Sample assignments to print per classification group}';

    protected $description = 'Conservatively backfill RecipientGender and life-stage RecipientTypes across the current catalog (published and draft). Does not publish, archive, or overwrite human-locked taxonomy.';

    public function handle(BackfillProductRecipientMerchandisingAction $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $productIds = $this->optionProductIds();
        $samples = max(1, (int) $this->option('samples'));

        $result = $backfill->execute(
            dryRun: $dryRun,
            limit: $this->optionInteger('limit'),
            productIds: $productIds === [] ? null : $productIds,
            routeAmbiguousToReview: (bool) $this->option('route-ambiguous-to-review'),
            sampleLimit: $samples,
        );

        $this->printResult($result, $samples);

        if ($dryRun) {
            $this->comment('Dry run completed. No taxonomy pivots, classification status, or publication were changed.');
        }

        return self::SUCCESS;
    }

    private function printResult(RecipientMerchandisingBackfillResult $result, int $samples): void
    {
        $inventory = $result->inventory;

        $this->info('Catalog inventory');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Product rows', $inventory['total_rows']],
                ['Excluded soft-deleted', $inventory['excluded_soft_deleted']],
                ['Excluded other/ineligible', $inventory['excluded_other']],
                ['Eligible for backfill', $inventory['eligible']],
                ['Eligible published', $inventory['eligible_published']],
                ['Eligible draft', $inventory['eligible_draft']],
                ['Eligible archived (reported separately)', $inventory['eligible_archived']],
            ],
        );

        $this->newLine();
        $this->info('Backfill outcomes');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Examined', $result->examined],
                ['Proposed changes', $result->dryRun ? $result->wouldApply : $result->applied],
                ['Human-locked skipped', $result->skippedHumanLocked],
                ['Already classified', $result->skippedAlreadyClassified],
                ['Insufficient evidence skipped', $result->noEvidence],
                ['Ambiguous', $result->ambiguous],
            ],
        );

        $this->newLine();
        $this->info('Classification summary');
        $this->table(
            ['Class', 'Count'],
            [
                ['Male', $result->genderCounts['male'] ?? 0],
                ['Female', $result->genderCounts['female'] ?? 0],
                ['Unisex', $result->genderCounts['unisex'] ?? 0],
                ['Ambiguous', $result->ambiguous],
                ['Baby', $result->recipientTypeCounts['baby'] ?? 0],
                ['School Student', $result->recipientTypeCounts['school-student'] ?? 0],
                ['College Student', $result->recipientTypeCounts['college-student'] ?? 0],
            ],
        );

        $groups = [
            'male' => 'Male samples',
            'female' => 'Female samples',
            'unisex' => 'Unisex samples',
            'ambiguous' => 'Ambiguous samples',
            'baby' => 'Baby samples',
            'school-student' => 'School Student samples',
            'college-student' => 'College Student samples',
        ];

        foreach ($groups as $key => $heading) {
            /** @var list<RecipientMerchandisingBackfillAssignment> $rows */
            $rows = array_slice($result->sampleGroups[$key] ?? [], 0, $samples);

            $this->newLine();
            $this->info($heading.' ('.count($rows).'/'.$samples.')');

            if ($rows === []) {
                $this->line('  (none)');

                continue;
            }

            $this->table(
                ['Product ID', 'Name', 'Gender', 'Life stages', 'Decision', 'Reasons'],
                collect($rows)
                    ->map(fn (RecipientMerchandisingBackfillAssignment $assignment) => [
                        $assignment->productId,
                        str($assignment->productName)->limit(48)->toString(),
                        $assignment->recipientGenderSlug ?? '—',
                        implode(', ', $assignment->recipientTypeSlugs) ?: '—',
                        $assignment->decision,
                        implode('; ', array_slice($assignment->reasons, 0, 3)),
                    ])
                    ->all(),
            );
        }
    }

    /**
     * @return list<int>
     */
    private function optionProductIds(): array
    {
        $raw = $this->option('product');

        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($value) => is_string($value) && ctype_digit($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function optionInteger(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '' || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
