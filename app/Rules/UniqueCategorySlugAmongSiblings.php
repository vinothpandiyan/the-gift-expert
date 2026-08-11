<?php

namespace App\Rules;

use App\Models\Category;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueCategorySlugAmongSiblings implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    public function __construct(
        protected ?int $ignoreCategoryId = null,
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
        $parentId = $this->data['parent_id'] ?? null;
        $parentId = $parentId === '' ? null : $parentId;

        $exists = Category::query()
            ->where('parent_id', $parentId)
            ->where('slug', $value)
            ->when($this->ignoreCategoryId, fn ($query) => $query->whereKeyNot($this->ignoreCategoryId))
            ->exists();

        if ($exists) {
            $fail('The slug has already been taken among sibling categories.');
        }
    }
}
