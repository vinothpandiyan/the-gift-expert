<?php

namespace App\Console\Commands;

use App\Actions\GapSourcing\BuildGapSourcingShortlistAction;
use App\GapSourcing\GapSourcingShortlistItem;
use App\GapSourcing\GapSourcingShortlistReport;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BuildGapSourcingShortlistCommand extends Command
{
    protected $signature = 'catalog:gap-sourcing-shortlist
        {file? : JSON file of discovered candidates}
        {--include-secondary : Also evaluate secondary gaps}
        {--json : Output machine-readable JSON}';

    protected $description = 'Triage gap-scoped sourcing candidates against the current catalog. Does not create Products, wishlists, publications, or archives.';

    public function handle(BuildGapSourcingShortlistAction $build): int
    {
        $path = $this->candidatePath();

        if (! is_file($path)) {
            $this->error('Candidate file not found: '.$path);

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->error('Candidate file must contain a JSON array or an object with a candidates key.');

            return self::FAILURE;
        }

        $payloads = array_is_list($decoded) ? $decoded : ($decoded['candidates'] ?? null);

        if (! is_array($payloads)) {
            $this->error('Candidate file must contain a JSON array or an object with a candidates key.');

            return self::FAILURE;
        }

        $report = $build->execute($payloads, (bool) $this->option('include-secondary'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->comment('No Product was created, published, archived, or added to a wishlist.');

            return self::SUCCESS;
        }

        $this->printHumanReport($report);

        return self::SUCCESS;
    }

    private function candidatePath(): string
    {
        $file = $this->argument('file');

        if (is_string($file) && $file !== '') {
            return $file;
        }

        $configured = (string) config('gap_sourcing.default_candidates_path');

        if ($configured === '') {
            throw new InvalidArgumentException('A candidate file is required.');
        }

        return base_path($configured);
    }

    private function printHumanReport(GapSourcingShortlistReport $report): void
    {
        $this->info('Targeted catalog gap sourcing shortlist');
        $this->line('Discovered: '.count($report->items));
        $this->line('Deduplicated: '.count($report->duplicateIds));
        $this->line('Shortlisted: '.count($report->shortlisted()));
        $this->newLine();

        $this->line('Gap coverage matrix');
        $this->table(
            ['Gap', 'Before', 'Kind', 'Strong', 'Shortlisted', 'Remaining need'],
            array_map(fn (array $row): array => [
                $row['gap'],
                $row['before'],
                $row['kind'],
                $row['strong_candidates'],
                $row['shortlisted'],
                $row['remaining_need'],
            ], $report->coverageMatrix()),
        );

        $this->line('Human shortlist');
        $this->table(
            ['Product', 'Price', 'Gap', 'Concept', 'GiftIntent', 'Why interesting', 'Nearest catalog alternative', 'Evidence'],
            array_map(fn (GapSourcingShortlistItem $item): array => [
                $item->candidate->title,
                $item->candidate->priceAmount === null ? '—' : '₹'.number_format($item->candidate->priceAmount, 0),
                $item->candidate->targetGap,
                $item->candidate->concept,
                implode(', ', $item->candidate->giftIntents),
                (string) $item->candidate->whyInteresting,
                $item->nearest->title ?? $item->nearest->summary,
                $item->candidate->evidenceConfidence,
            ], $report->shortlisted()),
        );

        $father = array_values(array_filter(
            $report->shortlisted(),
            fn (GapSourcingShortlistItem $item): bool => $item->candidate->targetGap === 'father',
        ));

        if ($father !== []) {
            $this->line('Father shortlist');
            $this->table(
                ['Candidate', 'Concept', 'Price band', 'Interest', 'GiftIntent', 'Why Father-specific', 'Nearest existing concept'],
                array_map(fn (GapSourcingShortlistItem $item): array => [
                    $item->candidate->title,
                    $item->candidate->concept,
                    $item->priceBand,
                    (string) $item->candidate->interest,
                    implode(', ', $item->candidate->giftIntents),
                    (string) $item->candidate->fatherSpecificReason,
                    $item->nearest->concept ?? $item->nearest->summary,
                ], $father),
            );
        }

        $experiences = array_values(array_filter(
            $report->shortlisted(),
            fn (GapSourcingShortlistItem $item): bool => $item->candidate->targetGap === 'experience_gifts',
        ));

        if ($experiences !== []) {
            $this->line('Experience shortlist');
            $this->table(
                ['Candidate', 'Type', 'Location', 'Price', 'Validity', 'Redemption', 'Affiliate', 'Recipient'],
                array_map(fn (GapSourcingShortlistItem $item): array => [
                    $item->candidate->title,
                    (string) $item->candidate->experienceType,
                    (string) $item->candidate->locationRestrictions,
                    $item->candidate->priceAmount === null ? '—' : '₹'.number_format($item->candidate->priceAmount, 0),
                    (string) $item->candidate->validity,
                    (string) $item->candidate->redemptionMethod,
                    (string) $item->candidate->affiliateViability,
                    (string) $item->candidate->recipientSuitability,
                ], $experiences),
            );
        }

        $this->comment('Discovery is not intake. Add approved Amazon candidates to 00 - Unclassified Gift Ideas manually.');
        $this->comment('No Product was created, published, archived, or added to a wishlist.');
    }
}
