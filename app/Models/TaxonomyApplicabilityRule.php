<?php

namespace App\Models;

use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class TaxonomyApplicabilityRule extends Model
{
    protected $fillable = [
        'source_dimension',
        'source_id',
        'target_dimension',
        'target_id',
        'effect',
        'reason',
        'is_active',
        'canonical_key',
    ];

    protected function casts(): array
    {
        return [
            'source_dimension' => TaxonomyDimension::class,
            'source_id' => 'integer',
            'target_dimension' => TaxonomyDimension::class,
            'target_id' => 'integer',
            'effect' => TaxonomyApplicabilityEffect::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $rule): void {
            $rule->assertValidPair();
            $rule->canonical_key = $rule->pairCanonicalKey();
            $rule->assertUniquePair();
        });
    }

    public static function canonicalKey(
        TaxonomyDimension $sourceDimension,
        int $sourceId,
        TaxonomyDimension $targetDimension,
        int $targetId,
    ): string {
        $left = sprintf('%s:%020d', $sourceDimension->value, $sourceId);
        $right = sprintf('%s:%020d', $targetDimension->value, $targetId);

        return $left <= $right
            ? $left.'|'.$right
            : $right.'|'.$left;
    }

    public function pairCanonicalKey(): string
    {
        return self::canonicalKey(
            $this->sourceDimension(),
            (int) $this->source_id,
            $this->targetDimension(),
            (int) $this->target_id,
        );
    }

    public function sourceDimension(): TaxonomyDimension
    {
        $dimension = $this->source_dimension;

        if (! $dimension instanceof TaxonomyDimension) {
            throw ValidationException::withMessages([
                'source_dimension' => ['The source type is not a supported taxonomy dimension.'],
            ]);
        }

        return $dimension;
    }

    public function targetDimension(): TaxonomyDimension
    {
        $dimension = $this->target_dimension;

        if (! $dimension instanceof TaxonomyDimension) {
            throw ValidationException::withMessages([
                'target_dimension' => ['The target type is not a supported taxonomy dimension.'],
            ]);
        }

        return $dimension;
    }

    public function sourceDisplayName(): string
    {
        return $this->sourceDimension()->getLabel().': '.$this->sourceDimension()->valueLabel((int) $this->source_id);
    }

    public function targetDisplayName(): string
    {
        return $this->targetDimension()->getLabel().': '.$this->targetDimension()->valueLabel((int) $this->target_id);
    }

    public function isSameEndpoint(): bool
    {
        return $this->sourceDimension() === $this->targetDimension()
            && (int) $this->source_id === (int) $this->target_id;
    }

    private function assertValidPair(): void
    {
        $sourceDimension = $this->sourceDimension();
        $targetDimension = $this->targetDimension();
        $sourceId = (int) $this->source_id;
        $targetId = (int) $this->target_id;

        if ($sourceId < 1) {
            throw ValidationException::withMessages([
                'source_id' => ['A source taxonomy value is required.'],
            ]);
        }

        if ($targetId < 1) {
            throw ValidationException::withMessages([
                'target_id' => ['A target taxonomy value is required.'],
            ]);
        }

        if ($this->isSameEndpoint()) {
            throw ValidationException::withMessages([
                'target_id' => ['Source and target cannot be the same taxonomy row.'],
            ]);
        }

        if (! $sourceDimension->endpointExists($sourceId)) {
            throw ValidationException::withMessages([
                'source_id' => ['The source taxonomy value does not exist.'],
            ]);
        }

        if (! $targetDimension->endpointExists($targetId)) {
            throw ValidationException::withMessages([
                'target_id' => ['The target taxonomy value does not exist.'],
            ]);
        }
    }

    private function assertUniquePair(): void
    {
        $exists = self::query()
            ->where('canonical_key', $this->canonical_key)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'source_id' => ['A rule already exists for this taxonomy pair. Reverse and contradictory ALLOW/EXCLUDE rows are not allowed.'],
            ]);
        }
    }
}
