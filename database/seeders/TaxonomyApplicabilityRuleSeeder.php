<?php

namespace Database\Seeders;

use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Models\TaxonomyApplicabilityRule;
use Illuminate\Database\Seeder;
use RuntimeException;

class TaxonomyApplicabilityRuleSeeder extends Seeder
{
    /**
     * @var array<string, array<string, int>>
     */
    private array $ids = [];

    public function run(): void
    {
        foreach ($this->allowRules() as [$sourceDimension, $sourceSlug, $targetDimension, $targetSlug, $reason]) {
            $this->upsert(
                $sourceDimension,
                $sourceSlug,
                $targetDimension,
                $targetSlug,
                TaxonomyApplicabilityEffect::Allow,
                $reason,
            );
        }

        foreach ($this->excludeRules() as [$sourceDimension, $sourceSlug, $targetDimension, $targetSlug, $reason]) {
            $this->upsert(
                $sourceDimension,
                $sourceSlug,
                $targetDimension,
                $targetSlug,
                TaxonomyApplicabilityEffect::Exclude,
                $reason,
            );
        }
    }

    /**
     * ALLOW source is the restricted value. Targets are the permitted counterparts
     * for that dimension; other values of that dimension are excluded.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private function allowRules(): array
    {
        return [
            ['occasion', 'raksha-bandhan', 'relationship', 'brother', 'Raksha Bandhan is intended for siblings.'],
            ['occasion', 'raksha-bandhan', 'relationship', 'sister', 'Raksha Bandhan is intended for siblings.'],
            ['occasion', 'mothers-day', 'relationship', 'mother', "Mother's Day is intended for mothers."],
            ['occasion', 'fathers-day', 'relationship', 'father', "Father's Day is intended for fathers."],
            ['occasion', 'valentines-day', 'relationship', 'husband', "Valentine's Day is treated as romantic gifting."],
            ['occasion', 'valentines-day', 'relationship', 'wife', "Valentine's Day is treated as romantic gifting."],
            ['occasion', 'valentines-day', 'relationship', 'boyfriend', "Valentine's Day is treated as romantic gifting."],
            ['occasion', 'valentines-day', 'relationship', 'girlfriend', "Valentine's Day is treated as romantic gifting."],
            ['occasion', 'bridal-shower', 'relationship', 'sister', 'Bridal showers are for the bride-to-be and her close circle.'],
            ['occasion', 'bridal-shower', 'relationship', 'girlfriend', 'Bridal showers are for the bride-to-be and her close circle.'],
            ['occasion', 'bridal-shower', 'relationship', 'friends', 'Bridal showers are for the bride-to-be and her close circle.'],
            ['occasion', 'bridal-shower', 'relationship', 'daughter', 'Bridal showers are for the bride-to-be and her close circle.'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private function excludeRules(): array
    {
        return [
            ['relationship', 'husband', 'occasion', 'baby-shower', 'Baby showers are not a husband gifting occasion.'],
            ['relationship', 'boyfriend', 'occasion', 'baby-shower', 'Baby showers are not a boyfriend gifting occasion.'],
            ['relationship', 'father', 'occasion', 'baby-shower', 'Baby showers are not a father gifting occasion.'],
            ['relationship', 'brother', 'occasion', 'baby-shower', 'Baby showers are maternal occasions, not brother gifting.'],
            ['relationship', 'son', 'occasion', 'baby-shower', 'Baby showers are not a son gifting occasion.'],
            ['relationship', 'boss', 'occasion', 'baby-shower', 'Baby showers are not a typical boss gifting occasion.'],
            ['relationship', 'colleagues', 'occasion', 'baby-shower', 'Baby showers are not a typical colleague gifting occasion.'],
            ['recipient_type', 'kids', 'occasion', 'baby-shower', 'Baby showers are not gifts-for-kids occasions.'],

            ['relationship', 'husband', 'gift_type', 'return-gifts', 'Return gifts are not ordinary husband gifting.'],
            ['relationship', 'wife', 'gift_type', 'return-gifts', 'Return gifts are not ordinary wife gifting.'],
            ['relationship', 'boyfriend', 'gift_type', 'return-gifts', 'Return gifts are not ordinary boyfriend gifting.'],
            ['relationship', 'girlfriend', 'gift_type', 'return-gifts', 'Return gifts are not ordinary girlfriend gifting.'],
            ['relationship', 'father', 'gift_type', 'return-gifts', 'Return gifts are not ordinary father gifting.'],
            ['relationship', 'mother', 'gift_type', 'return-gifts', 'Return gifts are not ordinary mother gifting.'],
            ['relationship', 'boss', 'gift_type', 'return-gifts', 'Return gifts are not ordinary boss gifting.'],
            ['relationship', 'colleagues', 'gift_type', 'return-gifts', 'Return gifts are not ordinary colleague gifting.'],

            ['recipient_type', 'kids', 'occasion', 'retirement', 'Retirement is not a kids gifting occasion.'],
            ['recipient_type', 'kids', 'occasion', 'new-job-promotion', 'Professional work occasions are not kids gifting.'],
            ['recipient_type', 'kids', 'occasion', 'farewell', 'Professional work occasions are not kids gifting.'],
            ['recipient_type', 'kids', 'occasion', 'bridal-shower', 'Bridal showers are not gifts-for-kids occasions.'],
            ['recipient_type', 'kids', 'occasion', 'valentines-day', "Romantic Valentine's Day is not a kids gifting occasion."],
            ['recipient_type', 'kids', 'occasion', 'mothers-day', "Mother's Day is not a gifts-for-kids occasion."],
            ['recipient_type', 'kids', 'occasion', 'fathers-day', "Father's Day is not a gifts-for-kids occasion."],

            ['recipient_type', 'teen', 'occasion', 'retirement', 'Retirement is not a teen gifting occasion.'],
        ];
    }

    private function upsert(
        string $sourceDimension,
        string $sourceSlug,
        string $targetDimension,
        string $targetSlug,
        TaxonomyApplicabilityEffect $effect,
        string $reason,
    ): void {
        $source = TaxonomyDimension::from($sourceDimension);
        $target = TaxonomyDimension::from($targetDimension);
        $sourceId = $this->id($source, $sourceSlug);
        $targetId = $this->id($target, $targetSlug);

        TaxonomyApplicabilityRule::query()->updateOrCreate(
            [
                'canonical_key' => TaxonomyApplicabilityRule::canonicalKey(
                    $source,
                    $sourceId,
                    $target,
                    $targetId,
                ),
            ],
            [
                'source_dimension' => $source,
                'source_id' => $sourceId,
                'target_dimension' => $target,
                'target_id' => $targetId,
                'effect' => $effect,
                'reason' => $reason,
                'is_active' => true,
            ],
        );
    }

    private function id(TaxonomyDimension $dimension, string $slug): int
    {
        if (! isset($this->ids[$dimension->value])) {
            $this->ids[$dimension->value] = $dimension->modelClass()::query()
                ->pluck('id', 'slug')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        $id = $this->ids[$dimension->value][$slug] ?? null;

        if ($id === null) {
            throw new RuntimeException("Missing {$dimension->value} slug [{$slug}] for applicability seeding.");
        }

        return $id;
    }
}
