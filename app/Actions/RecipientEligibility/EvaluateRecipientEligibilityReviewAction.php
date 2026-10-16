<?php

namespace App\Actions\RecipientEligibility;

use InvalidArgumentException;

/**
 * Scores a human-reviewed inferred-product sample (CSV exported by the audit) per
 * relationship. A relationship passes the activation gate only when every sampled
 * row is reviewed, enough rows were reviewed, and the share marked "keep"
 * (borderline counts as not suitable) reaches the configured threshold.
 */
class EvaluateRecipientEligibilityReviewAction
{
    public const REVIEW_KEEP = 'keep';

    public const REVIEW_BORDERLINE = 'borderline';

    public const REVIEW_REJECT = 'reject';

    /**
     * @return array<string, array<string, mixed>> keyed by relationship slug
     */
    public function execute(string $csvPath): array
    {
        if (! is_file($csvPath)) {
            throw new InvalidArgumentException("Review file not found: {$csvPath}");
        }

        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle, escape: '\\');

        if (! is_array($header) || ! in_array('relationship', $header, true) || ! in_array('review', $header, true) || ! in_array('product_id', $header, true)) {
            throw new InvalidArgumentException('Review file must contain relationship, product_id and review columns.');
        }

        $rows = [];

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            if (count($row) === count($header)) {
                $rows[] = array_combine($header, $row);
            }
        }

        fclose($handle);

        $minSuitability = (float) config('recipient_eligibility.audit.min_suitability', 0.95);
        $minReviewed = (int) config('recipient_eligibility.audit.min_reviewed', 30);
        $results = [];

        foreach (collect($rows)->groupBy('relationship') as $slug => $group) {
            $counts = [self::REVIEW_KEEP => 0, self::REVIEW_BORDERLINE => 0, self::REVIEW_REJECT => 0, 'unreviewed' => 0];

            foreach ($group as $row) {
                $review = strtolower(trim((string) $row['review']));
                $counts[array_key_exists($review, $counts) && $review !== 'unreviewed' ? $review : 'unreviewed']++;
            }

            $reviewed = $counts[self::REVIEW_KEEP] + $counts[self::REVIEW_BORDERLINE] + $counts[self::REVIEW_REJECT];
            $sampleRows = $group->count();
            $suitability = $reviewed > 0 ? $counts[self::REVIEW_KEEP] / $reviewed : 0.0;
            $required = min($minReviewed, $sampleRows);

            $results[(string) $slug] = [
                'sample_rows' => $sampleRows,
                'reviewed' => $reviewed,
                'keep' => $counts[self::REVIEW_KEEP],
                'borderline' => $counts[self::REVIEW_BORDERLINE],
                'reject' => $counts[self::REVIEW_REJECT],
                'unreviewed' => $counts['unreviewed'],
                'suitability' => round($suitability, 4),
                'threshold' => $minSuitability,
                'passed' => $counts['unreviewed'] === 0 && $reviewed >= $required && $reviewed > 0 && $suitability >= $minSuitability,
                'sample_sha1' => sha1($group->pluck('product_id')->sort()->implode(',')),
                'reviewed_at' => now()->toIso8601String(),
            ];
        }

        return $results;
    }
}
