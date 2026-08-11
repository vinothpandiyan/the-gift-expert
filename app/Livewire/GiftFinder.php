<?php

namespace App\Livewire;

use App\Actions\Recommendation\GenerateRecommendationsAction;
use App\Models\BudgetRange;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Support\DiscoveryUrl;
use App\Support\PageMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Livewire\Component;

class GiftFinder extends Component
{
    public mixed $occasion_id = null;

    public mixed $relationship_id = null;

    public mixed $recipient_type_id = null;

    /** @var list<int|string> */
    public array $interest_ids = [];

    public mixed $profession_id = null;

    public mixed $gift_type_id = null;

    public mixed $budget_range_id = null;

    public function submit(GenerateRecommendationsAction $action): void
    {
        $validated = $this->validate($this->rules());

        $interestIds = collect($validated['interest_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $session = $action->execute([
            'occasion_id' => $this->nullableId($validated['occasion_id'] ?? null),
            'relationship_id' => $this->nullableId($validated['relationship_id'] ?? null),
            'recipient_type_id' => $this->nullableId($validated['recipient_type_id'] ?? null),
            'profession_id' => $this->nullableId($validated['profession_id'] ?? null),
            'gift_type_id' => $this->nullableId($validated['gift_type_id'] ?? null),
            'budget_range_id' => $this->nullableId($validated['budget_range_id'] ?? null),
            'interest_ids' => $interestIds,
        ]);

        $this->redirect(DiscoveryUrl::finderResults($session->uuid));
    }

    public function render(): View
    {
        return view('livewire.gift-finder', [
            'occasions' => $this->activeOptions(Occasion::query()),
            'relationships' => $this->activeOptions(Relationship::query()),
            'recipientTypes' => $this->activeOptions(RecipientType::query()),
            'interests' => $this->activeOptions(Interest::query()),
            'professions' => $this->activeOptions(Profession::query()),
            'giftTypes' => $this->activeOptions(GiftType::query()),
            'budgetRanges' => $this->activeOptions(BudgetRange::query()),
            'maxInterests' => (int) config('gift_recommendations.max_interests'),
        ])
            ->extends('layouts.public')
            ->title(PageMeta::finderTitle())
            ->layoutData([
                'seoDescription' => PageMeta::finderDescription(),
                'seoCanonical' => PageMeta::finderCanonical(),
                'seoRobots' => 'index, follow',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $maxInterests = (int) config('gift_recommendations.max_interests');

        return [
            'occasion_id' => ['nullable', $this->activeExistsRule('occasions')],
            'relationship_id' => ['nullable', $this->activeExistsRule('relationships')],
            'recipient_type_id' => ['nullable', $this->activeExistsRule('recipient_types')],
            'profession_id' => ['nullable', $this->activeExistsRule('professions')],
            'gift_type_id' => ['nullable', $this->activeExistsRule('gift_types')],
            'budget_range_id' => ['nullable', $this->activeExistsRule('budget_ranges')],
            'interest_ids' => ['nullable', 'array', 'max:'.$maxInterests],
            'interest_ids.*' => ['integer', $this->activeExistsRule('interests')],
        ];
    }

    private function activeExistsRule(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where(fn (QueryBuilder $query) => $query
                ->where('is_active', true)
                ->whereNull('deleted_at'));
    }

    /**
     * @return Collection<int, object{id: int, name: string}>
     */
    private function activeOptions(EloquentBuilder $query): Collection
    {
        return $query
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'interest_ids.max' => 'You may select up to '.config('gift_recommendations.max_interests').' interests.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'occasion_id' => 'occasion',
            'relationship_id' => 'relationship',
            'recipient_type_id' => 'recipient',
            'profession_id' => 'profession',
            'gift_type_id' => 'gift type',
            'budget_range_id' => 'budget',
            'interest_ids' => 'interests',
            'interest_ids.*' => 'interest',
        ];
    }
}
