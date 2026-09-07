<?php

namespace App\Rules;

use App\Enums\TaxonomyDimension;
use App\Models\TaxonomyApplicabilityRule;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidTaxonomyApplicabilityPair implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    public function __construct(
        protected ?int $ignoreRuleId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $sourceDimension = $this->dimension($this->data['source_dimension'] ?? null);
        $targetDimension = $this->dimension($this->data['target_dimension'] ?? null);
        $sourceId = (int) ($this->data['source_id'] ?? 0);
        $targetId = (int) ($this->data['target_id'] ?? 0);

        if ($sourceDimension === null) {
            $fail('The source type is not a supported taxonomy dimension.');

            return;
        }

        if ($targetDimension === null) {
            $fail('The target type is not a supported taxonomy dimension.');

            return;
        }

        if ($sourceId < 1 || $targetId < 1) {
            return;
        }

        if ($sourceDimension === $targetDimension && $sourceId === $targetId) {
            $fail('Source and target cannot be the same taxonomy row.');

            return;
        }

        if (! $sourceDimension->endpointExists($sourceId)) {
            $fail('The source taxonomy value does not exist.');

            return;
        }

        if (! $targetDimension->endpointExists($targetId)) {
            $fail('The target taxonomy value does not exist.');

            return;
        }

        $exists = TaxonomyApplicabilityRule::query()
            ->where('canonical_key', TaxonomyApplicabilityRule::canonicalKey(
                $sourceDimension,
                $sourceId,
                $targetDimension,
                $targetId,
            ))
            ->when($this->ignoreRuleId, fn ($query) => $query->whereKeyNot($this->ignoreRuleId))
            ->exists();

        if ($exists) {
            $fail('A rule already exists for this taxonomy pair. Reverse and contradictory ALLOW/EXCLUDE rows are not allowed.');
        }
    }

    private function dimension(mixed $value): ?TaxonomyDimension
    {
        if ($value instanceof TaxonomyDimension) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return TaxonomyDimension::tryFrom((string) $value);
    }
}
