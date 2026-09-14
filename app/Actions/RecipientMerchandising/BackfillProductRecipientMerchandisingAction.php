<?php

namespace App\Actions\RecipientMerchandising;

use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Product;
use App\Models\RecipientGender;
use App\Models\RecipientType;
use App\RecipientMerchandising\RecipientMerchandisingBackfillAssignment;
use App\RecipientMerchandising\RecipientMerchandisingBackfillResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Conservative heuristic backfill for RecipientGender and life-stage RecipientTypes.
 * Does not publish, archive, or reclassify unrelated taxonomy dimensions.
 *
 * Scope is the legitimate current catalog (published and draft), not only launch-published Gifts.
 * Soft-deleted rows are excluded. Archived rows are reported separately and still examined.
 */
class BackfillProductRecipientMerchandisingAction
{
    private const SAMPLE_LIMIT = 5;

    /**
     * @param  list<int>|null  $productIds
     */
    public function execute(
        bool $dryRun = true,
        ?int $limit = null,
        ?array $productIds = null,
        bool $routeAmbiguousToReview = false,
        int $sampleLimit = self::SAMPLE_LIMIT,
    ): RecipientMerchandisingBackfillResult {
        $sampleLimit = max(1, $sampleLimit);
        $inventory = $this->inventory($productIds);
        $genderIds = RecipientGender::query()
            ->where('is_active', true)
            ->pluck('id', 'slug')
            ->mapWithKeys(fn ($id, $slug): array => [(string) $slug => (int) $id])
            ->all();

        $lifeStageIds = RecipientType::query()
            ->whereIn('slug', ['baby', 'school-student', 'college-student'])
            ->where('is_active', true)
            ->pluck('id', 'slug')
            ->mapWithKeys(fn ($id, $slug): array => [(string) $slug => (int) $id])
            ->all();

        $assignments = [];
        $sampleGroups = [
            'male' => [],
            'female' => [],
            'unisex' => [],
            'ambiguous' => [],
            'baby' => [],
            'school-student' => [],
            'college-student' => [],
        ];
        $genderCounts = [
            RecipientGender::SLUG_MALE => 0,
            RecipientGender::SLUG_FEMALE => 0,
            RecipientGender::SLUG_UNISEX => 0,
        ];
        $recipientTypeCounts = [
            'baby' => 0,
            'school-student' => 0,
            'college-student' => 0,
        ];
        $examined = 0;
        $wouldApply = 0;
        $applied = 0;
        $skippedHumanLocked = 0;
        $skippedAlreadyClassified = 0;
        $ambiguous = 0;
        $noEvidence = 0;

        $this->scopedQuery($productIds, $limit)
            ->with(['recipientGenders:id,slug', 'recipientTypes:id,slug'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $products) use (
                $dryRun,
                $routeAmbiguousToReview,
                $genderIds,
                $lifeStageIds,
                $sampleLimit,
                &$assignments,
                &$sampleGroups,
                &$genderCounts,
                &$recipientTypeCounts,
                &$examined,
                &$wouldApply,
                &$applied,
                &$skippedHumanLocked,
                &$skippedAlreadyClassified,
                &$ambiguous,
                &$noEvidence,
            ): void {
                foreach ($products as $product) {
                    $examined++;

                    if ($product->taxonomy_classification_status?->isHumanLocked()) {
                        $skippedHumanLocked++;

                        continue;
                    }

                    $proposal = $this->propose($product);

                    if ($proposal['decision'] === 'already_classified') {
                        $skippedAlreadyClassified++;

                        continue;
                    }

                    if ($proposal['decision'] === 'ambiguous') {
                        $ambiguous++;
                        $assignment = new RecipientMerchandisingBackfillAssignment(
                            productId: $product->id,
                            productName: (string) $product->name,
                            recipientGenderSlug: null,
                            recipientTypeSlugs: $proposal['recipient_type_slugs'],
                            decision: 'ambiguous',
                            reasons: $proposal['reasons'],
                        );
                        $assignments[] = $assignment;
                        $this->collectSamples($sampleGroups, $assignment, $sampleLimit);

                        if (! $dryRun && $routeAmbiguousToReview && $proposal['recipient_type_slugs'] === []) {
                            $this->routeToReview($product, $proposal['reasons']);
                        }

                        if (! $dryRun && $proposal['recipient_type_slugs'] !== []) {
                            $this->attachRecipientTypes($product, $proposal['recipient_type_slugs'], $lifeStageIds);
                        }

                        foreach ($proposal['recipient_type_slugs'] as $slug) {
                            if (isset($recipientTypeCounts[$slug])) {
                                $recipientTypeCounts[$slug]++;
                            }
                        }

                        continue;
                    }

                    if ($proposal['decision'] === 'no_evidence') {
                        $noEvidence++;

                        continue;
                    }

                    $wouldApply++;

                    if (isset($genderCounts[$proposal['recipient_gender_slug']])) {
                        $genderCounts[$proposal['recipient_gender_slug']]++;
                    }

                    foreach ($proposal['recipient_type_slugs'] as $slug) {
                        if (isset($recipientTypeCounts[$slug])) {
                            $recipientTypeCounts[$slug]++;
                        }
                    }

                    $assignment = new RecipientMerchandisingBackfillAssignment(
                        productId: $product->id,
                        productName: (string) $product->name,
                        recipientGenderSlug: $proposal['recipient_gender_slug'],
                        recipientTypeSlugs: $proposal['recipient_type_slugs'],
                        decision: 'apply',
                        reasons: $proposal['reasons'],
                    );
                    $assignments[] = $assignment;
                    $this->collectSamples($sampleGroups, $assignment, $sampleLimit);

                    if ($dryRun) {
                        continue;
                    }

                    $this->apply($product, $proposal, $genderIds, $lifeStageIds);
                    $applied++;
                }
            });

        return new RecipientMerchandisingBackfillResult(
            inventory: $inventory,
            examined: $examined,
            wouldApply: $wouldApply,
            applied: $applied,
            skippedHumanLocked: $skippedHumanLocked,
            skippedAlreadyClassified: $skippedAlreadyClassified,
            ambiguous: $ambiguous,
            noEvidence: $noEvidence,
            dryRun: $dryRun,
            genderCounts: $genderCounts,
            recipientTypeCounts: $recipientTypeCounts,
            assignments: $assignments,
            sampleGroups: $sampleGroups,
        );
    }

    /**
     * @param  list<int>|null  $productIds
     * @return array{
     *     total_rows: int,
     *     excluded_soft_deleted: int,
     *     excluded_other: int,
     *     eligible: int,
     *     eligible_published: int,
     *     eligible_draft: int,
     *     eligible_archived: int,
     * }
     */
    private function inventory(?array $productIds): array
    {
        $base = Product::query()->withTrashed();

        if ($productIds !== null && $productIds !== []) {
            $base->whereIn('id', $productIds);
        }

        $totalRows = (clone $base)->count();
        $excludedSoftDeleted = (clone $base)->onlyTrashed()->count();
        $eligibleQuery = Product::query();

        if ($productIds !== null && $productIds !== []) {
            $eligibleQuery->whereIn('id', $productIds);
        }

        $eligible = (clone $eligibleQuery)->count();
        $eligiblePublished = (clone $eligibleQuery)->where('status', ProductStatus::Published)->count();
        $eligibleDraft = (clone $eligibleQuery)->where('status', ProductStatus::Draft)->count();
        $eligibleArchived = (clone $eligibleQuery)->where('status', ProductStatus::Archived)->count();
        $excludedOther = max(0, $totalRows - $excludedSoftDeleted - $eligible);

        return [
            'total_rows' => $totalRows,
            'excluded_soft_deleted' => $excludedSoftDeleted,
            'excluded_other' => $excludedOther,
            'eligible' => $eligible,
            'eligible_published' => $eligiblePublished,
            'eligible_draft' => $eligibleDraft,
            'eligible_archived' => $eligibleArchived,
        ];
    }

    /**
     * @param  array<string, list<RecipientMerchandisingBackfillAssignment>>  $sampleGroups
     */
    private function collectSamples(array &$sampleGroups, RecipientMerchandisingBackfillAssignment $assignment, int $sampleLimit): void
    {
        if ($assignment->decision === 'ambiguous' && count($sampleGroups['ambiguous']) < $sampleLimit) {
            $sampleGroups['ambiguous'][] = $assignment;
        }

        if (
            $assignment->recipientGenderSlug !== null
            && isset($sampleGroups[$assignment->recipientGenderSlug])
            && count($sampleGroups[$assignment->recipientGenderSlug]) < $sampleLimit
        ) {
            $sampleGroups[$assignment->recipientGenderSlug][] = $assignment;
        }

        foreach ($assignment->recipientTypeSlugs as $slug) {
            if (! isset($sampleGroups[$slug]) || count($sampleGroups[$slug]) >= $sampleLimit) {
                continue;
            }

            $sampleGroups[$slug][] = $assignment;
        }
    }

    /**
     * @param  list<int>|null  $productIds
     */
    private function scopedQuery(?array $productIds, ?int $limit): Builder
    {
        $query = Product::query();

        if ($productIds !== null && $productIds !== []) {
            $query->whereIn('id', $productIds);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    /**
     * @return array{
     *     decision: string,
     *     recipient_gender_slug: ?string,
     *     recipient_type_slugs: list<string>,
     *     reasons: list<string>
     * }
     */
    private function propose(Product $product): array
    {
        $existingGender = $product->recipientGenders->pluck('slug')->all();
        $existingTypes = $product->recipientTypes->pluck('slug')->all();
        $text = $this->evidenceText($product);

        $lifeStages = $this->detectLifeStages($text, $existingTypes);
        $gender = $this->detectGender($text);

        $hasGenderWork = $existingGender === [] && $gender['slug'] !== null;
        $hasTypeWork = $lifeStages['slugs'] !== [];

        if ($existingGender !== [] && ! $hasTypeWork) {
            return [
                'decision' => 'already_classified',
                'recipient_gender_slug' => null,
                'recipient_type_slugs' => [],
                'reasons' => ['recipient_gender_already_set'],
            ];
        }

        if ($gender['decision'] === 'ambiguous' && $existingGender === []) {
            return [
                'decision' => 'ambiguous',
                'recipient_gender_slug' => null,
                'recipient_type_slugs' => $lifeStages['slugs'],
                'reasons' => array_values(array_unique([...$gender['reasons'], ...$lifeStages['reasons']])),
            ];
        }

        if (! $hasGenderWork && ! $hasTypeWork) {
            return [
                'decision' => 'no_evidence',
                'recipient_gender_slug' => null,
                'recipient_type_slugs' => [],
                'reasons' => ['no_high_confidence_evidence'],
            ];
        }

        return [
            'decision' => 'apply',
            'recipient_gender_slug' => $hasGenderWork ? $gender['slug'] : null,
            'recipient_type_slugs' => $lifeStages['slugs'],
            'reasons' => array_values(array_unique([
                ...($hasGenderWork ? $gender['reasons'] : []),
                ...$lifeStages['reasons'],
            ])),
        ];
    }

    /**
     * @param  array{
     *     decision: string,
     *     recipient_gender_slug: ?string,
     *     recipient_type_slugs: list<string>,
     *     reasons: list<string>
     * }  $proposal
     * @param  array<string, int>  $genderIds
     * @param  array<string, int>  $lifeStageIds
     */
    private function apply(Product $product, array $proposal, array $genderIds, array $lifeStageIds): void
    {
        if ($proposal['recipient_gender_slug'] !== null && isset($genderIds[$proposal['recipient_gender_slug']])) {
            if ($product->recipientGenders()->exists() === false) {
                $product->recipientGenders()->sync([$genderIds[$proposal['recipient_gender_slug']]]);
            }
        }

        $this->attachRecipientTypes($product, $proposal['recipient_type_slugs'], $lifeStageIds);
    }

    /**
     * @param  list<string>  $slugs
     * @param  array<string, int>  $lifeStageIds
     */
    private function attachRecipientTypes(Product $product, array $slugs, array $lifeStageIds): void
    {
        $attach = [];

        foreach ($slugs as $slug) {
            if (! isset($lifeStageIds[$slug])) {
                continue;
            }

            $attach[] = $lifeStageIds[$slug];
        }

        if ($attach !== []) {
            $product->recipientTypes()->syncWithoutDetaching($attach);
        }
    }

    /**
     * @param  list<string>  $reasons
     */
    private function routeToReview(Product $product, array $reasons): void
    {
        if ($product->taxonomy_classification_status?->isHumanLocked()) {
            return;
        }

        $existing = is_array($product->taxonomy_review_reasons)
            ? $product->taxonomy_review_reasons
            : [];

        $product->forceFill([
            'taxonomy_classification_status' => TaxonomyClassificationStatus::Review,
            'taxonomy_review_reasons' => array_values(array_unique([
                ...$existing,
                'recipient_gender_ambiguous',
                ...$reasons,
            ])),
        ])->save();
    }

    private function evidenceText(Product $product): string
    {
        return mb_strtolower(trim(implode(' ', array_filter([
            (string) $product->name,
            (string) $product->brand,
            (string) $product->short_description,
            (string) $product->description,
        ]))));
    }

    /**
     * @return array{decision: string, slug: ?string, reasons: list<string>}
     */
    private function detectGender(string $text): array
    {
        $maleHits = $this->matchAny($text, [
            "men's",
            'mens ',
            ' for men',
            'for him',
            'gentlemen',
            'male grooming',
            "boy's",
            'boys ',
            ' for boys',
            'baby boy',
            'babyboy',
        ]);
        $femaleHits = $this->matchAny($text, [
            "women's",
            'womens ',
            ' for women',
            'for her',
            'ladies',
            "girl's",
            'girls ',
            ' for girls',
            'baby girl',
            'babygirl',
            'jewellery for women',
            'jewelry for women',
            'handbag',
            'purse for',
        ]);
        $unisexHits = $this->matchAny($text, [
            'board game',
            'card game',
            'bluetooth speaker',
            'smart speaker',
            'echo dot',
            'desk organizer',
            'desk accessory',
            'wireless mouse',
            'mechanical keyboard',
            'power bank',
            'usb-c hub',
            'notebook set',
            'jigsaw puzzle',
        ]);

        if ($maleHits !== [] && $femaleHits !== []) {
            return [
                'decision' => 'ambiguous',
                'slug' => null,
                'reasons' => ['conflicting_male_and_female_evidence', ...$maleHits, ...$femaleHits],
            ];
        }

        if ($maleHits !== []) {
            return [
                'decision' => 'apply',
                'slug' => RecipientGender::SLUG_MALE,
                'reasons' => $maleHits,
            ];
        }

        if ($femaleHits !== []) {
            return [
                'decision' => 'apply',
                'slug' => RecipientGender::SLUG_FEMALE,
                'reasons' => $femaleHits,
            ];
        }

        if ($unisexHits !== []) {
            return [
                'decision' => 'apply',
                'slug' => RecipientGender::SLUG_UNISEX,
                'reasons' => $unisexHits,
            ];
        }

        if ($this->looksAmbiguous($text)) {
            return [
                'decision' => 'ambiguous',
                'slug' => null,
                'reasons' => ['ambiguous_gender_signals'],
            ];
        }

        return [
            'decision' => 'no_evidence',
            'slug' => null,
            'reasons' => ['no_high_confidence_evidence'],
        ];
    }

    /**
     * @param  list<string>  $existingTypes
     * @return array{slugs: list<string>, reasons: list<string>}
     */
    private function detectLifeStages(string $text, array $existingTypes): array
    {
        $slugs = [];
        $reasons = [];

        if (! in_array('baby', $existingTypes, true) && $this->matchAny($text, [
            'baby ',
            'infant',
            'newborn',
            '0-12 month',
            '0-6 month',
            'teether',
            'baby sensory',
        ]) !== []) {
            $slugs[] = 'baby';
            $reasons[] = 'baby_life_stage_evidence';
        }

        if (! in_array('school-student', $existingTypes, true) && $this->matchAny($text, [
            'school bag',
            'school backpack',
            'for school',
            'school student',
            'stationery for school',
            'geometry box',
        ]) !== []) {
            $slugs[] = 'school-student';
            $reasons[] = 'school_student_evidence';
        }

        if (! in_array('college-student', $existingTypes, true) && $this->matchAny($text, [
            'college ',
            'university',
            'hostel',
            'for students',
            'laptop backpack',
            'campus ',
        ]) !== []) {
            $slugs[] = 'college-student';
            $reasons[] = 'college_student_evidence';
        }

        return ['slugs' => $slugs, 'reasons' => $reasons];
    }

    private function looksAmbiguous(string $text): bool
    {
        return $this->matchAny($text, [
            'perfume',
            'fragrance',
            'cologne',
            'watch',
            'wallet',
            'jewellery',
            'jewelry',
            'ring ',
            'necklace',
            'bracelet',
            'apparel',
            't-shirt',
            'hoodie',
            'sneaker',
        ]) !== [];
    }

    /**
     * @param  list<string>  $needles
     * @return list<string>
     */
    private function matchAny(string $haystack, array $needles): array
    {
        $hits = [];

        foreach ($needles as $needle) {
            // Avoid substring false positives such as "men's" inside "women's".
            $pattern = '/(?<![\p{L}])'.preg_quote($needle, '/').'/u';

            if (preg_match($pattern, $haystack) === 1) {
                $hits[] = 'matched:'.$needle;
            }
        }

        return $hits;
    }
}
