<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BudgetRange extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'min_amount',
        'max_amount',
        'currency',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function containsAmount(mixed $amount): bool
    {
        if ($amount === null || $amount === '' || ! is_numeric((string) $amount)) {
            return false;
        }

        $value = $this->normalizeDecimal($amount);

        if ($this->min_amount !== null && bccomp($value, $this->normalizeDecimal($this->min_amount), 2) < 0) {
            return false;
        }

        if ($this->max_amount !== null && bccomp($value, $this->normalizeDecimal($this->max_amount), 2) >= 0) {
            return false;
        }

        return true;
    }

    public function constrainPriceQuery(Builder $query, string $column = 'price_amount'): void
    {
        [$sql, $bindings] = $this->priceMatchSql($column);

        if ($sql === '') {
            return;
        }

        $query->whereRaw($sql, $bindings);
    }

    /**
     * Canonical half-open range predicate: min inclusive, max exclusive.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function priceMatchSql(string $column = 'price_amount'): array
    {
        $parts = [];
        $bindings = [];

        if ($this->min_amount !== null) {
            $parts[] = "{$column} >= ?";
            $bindings[] = $this->min_amount;
        }

        if ($this->max_amount !== null) {
            $parts[] = "{$column} < ?";
            $bindings[] = $this->max_amount;
        }

        return [implode(' AND ', $parts), $bindings];
    }

    private function normalizeDecimal(mixed $amount): string
    {
        return bcadd((string) $amount, '0', 2);
    }
}
