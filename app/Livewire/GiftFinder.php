<?php

namespace App\Livewire;

use App\Actions\Recommendation\GenerateRecommendationsAction;
use App\Models\BudgetRange;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\RecommendationSession;
use App\Models\Relationship;
use App\Support\DiscoveryUrl;
use App\Support\PageMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GiftFinder extends Component
{
    public int $step = 1;

    public mixed $occasion_id = null;

    public mixed $relationship_id = null;

    public mixed $recipient_type_id = null;

    /** @var list<int|string> */
    public array $interest_ids = [];

    public mixed $profession_id = null;

    public mixed $gift_type_id = null;

    public mixed $budget_range_id = null;

    public ?string $stepError = null;

    public bool $finding = false;

    public function mount(?string $session = null): void
    {
        $uuid = is_string($session) && $session !== ''
            ? $session
            : request()->query('session');

        if (is_string($uuid) && $uuid !== '') {
            $this->hydrateFromSession($uuid);

            return;
        }

        $this->hydrateFromQuery();
    }

    public function selectRelationship(int $id): void
    {
        $this->relationship_id = $id;
        $this->stepError = null;
    }

    public function selectOccasion(int $id): void
    {
        $this->occasion_id = $id;
        $this->stepError = null;
    }

    public function toggleInterest(int $id): void
    {
        $ids = collect($this->interest_ids)
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        if ($ids->contains($id)) {
            $this->interest_ids = $ids
                ->reject(fn (int $value) => $value === $id)
                ->values()
                ->all();
            $this->stepError = null;

            return;
        }

        if ($ids->count() >= $this->maxInterests()) {
            return;
        }

        $this->interest_ids = $ids->push($id)->all();
        $this->stepError = null;
    }

    public function selectRecipientType(int $id): void
    {
        $this->recipient_type_id = $this->nullableId($this->recipient_type_id) === $id ? null : $id;
    }

    public function selectProfession(int $id): void
    {
        $this->profession_id = $this->nullableId($this->profession_id) === $id ? null : $id;
    }

    public function selectGiftType(int $id): void
    {
        $this->gift_type_id = $this->nullableId($this->gift_type_id) === $id ? null : $id;
    }

    public function selectBudget(int $id): void
    {
        $this->budget_range_id = $id;
        $this->stepError = null;
    }

    public function back(): void
    {
        $this->stepError = null;
        $this->step = max(1, $this->step - 1);
    }

    public function continueStep(): void
    {
        if (! $this->canContinue()) {
            $this->stepError = $this->stepErrorMessage();

            return;
        }

        $this->stepError = null;
        $this->step = min(5, $this->step + 1);
    }

    public function skipAbout(): void
    {
        if ($this->step !== 4) {
            return;
        }

        $this->stepError = null;
        $this->step = 5;
    }

    public function submit(GenerateRecommendationsAction $action): void
    {
        if ($this->finding) {
            return;
        }

        $this->finding = true;

        if ($this->step === 5 && ! $this->canContinue()) {
            $this->finding = false;
            $this->stepError = $this->stepErrorMessage();

            return;
        }

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
        return view('livewire.gift-finder')
            ->extends('layouts.public')
            ->title(PageMeta::finderTitle())
            ->layoutData([
                'seoDescription' => PageMeta::finderDescription(),
                'seoCanonical' => PageMeta::finderCanonical(),
                'seoRobots' => 'index, follow',
            ]);
    }

    public function canContinue(): bool
    {
        return match ($this->step) {
            1 => $this->nullableId($this->relationship_id) !== null,
            2 => $this->nullableId($this->occasion_id) !== null,
            3 => $this->selectedInterestCount() >= 1,
            4 => true,
            5 => $this->nullableId($this->budget_range_id) !== null,
            default => false,
        };
    }

    /**
     * @return Collection<int, object{id: int, name: string, description?: string|null}>
     */
    #[Computed]
    public function relationships(): Collection
    {
        return $this->activeOptions(Relationship::query(), ['id', 'name', 'description']);
    }

    /**
     * @return Collection<int, object{id: int, name: string, description?: string|null}>
     */
    #[Computed]
    public function occasions(): Collection
    {
        return $this->activeOptions(Occasion::query(), ['id', 'name', 'description']);
    }

    /**
     * @return Collection<int, object{id: int, name: string}>
     */
    #[Computed]
    public function interests(): Collection
    {
        return $this->activeOptions(Interest::query());
    }

    /**
     * @return Collection<int, object{id: int, name: string, description?: string|null}>
     */
    #[Computed]
    public function recipientTypes(): Collection
    {
        return $this->activeOptions(RecipientType::query(), ['id', 'name', 'description']);
    }

    /**
     * @return Collection<int, object{id: int, name: string, description?: string|null}>
     */
    #[Computed]
    public function professions(): Collection
    {
        return $this->activeOptions(Profession::query(), ['id', 'name', 'description']);
    }

    /**
     * @return Collection<int, object{id: int, name: string, description?: string|null}>
     */
    #[Computed]
    public function giftTypes(): Collection
    {
        return $this->activeOptions(GiftType::query(), ['id', 'name', 'description']);
    }

    /**
     * @return Collection<int, object{id: int, name: string}>
     */
    #[Computed]
    public function budgetRanges(): Collection
    {
        return $this->activeOptions(BudgetRange::query());
    }

    public function stepLabel(): string
    {
        return match ($this->step) {
            1 => 'Recipient',
            2 => 'Occasion',
            3 => 'Interests',
            4 => 'About them',
            5 => 'Budget',
            default => '',
        };
    }

    public function maxInterests(): int
    {
        return (int) config('gift_recommendations.max_interests');
    }

    public function selectedInterestCount(): int
    {
        return collect($this->interest_ids)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->count();
    }

    public function interestIsSelected(int $id): bool
    {
        return collect($this->interest_ids)
            ->map(fn ($value) => (int) $value)
            ->contains($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $maxInterests = $this->maxInterests();

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
     * @param  list<string>  $columns
     * @return Collection<int, object{id: int, name: string}>
     */
    private function activeOptions(EloquentBuilder $query, array $columns = ['id', 'name']): Collection
    {
        return $query
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get($columns);
    }

    private function hydrateFromSession(string $uuid): void
    {
        $session = RecommendationSession::query()
            ->with('interests:id')
            ->where('uuid', $uuid)
            ->first();

        if ($session === null) {
            return;
        }

        $this->relationship_id = $session->relationship_id;
        $this->occasion_id = $session->occasion_id;
        $this->recipient_type_id = $session->recipient_type_id;
        $this->profession_id = $session->profession_id;
        $this->gift_type_id = $session->gift_type_id;
        $this->budget_range_id = $session->budget_range_id;
        $this->interest_ids = $session->interests
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take($this->maxInterests())
            ->values()
            ->all();
        $this->step = 1;
    }

    private function hydrateFromQuery(): void
    {
        $this->relationship_id = $this->activeIdFromSlug(Relationship::query(), request()->query('relationship'));
        $this->occasion_id = $this->activeIdFromSlug(Occasion::query(), request()->query('occasion'));
        $this->budget_range_id = $this->activeIdFromSlug(BudgetRange::query(), request()->query('budget'));
    }

    private function activeIdFromSlug(EloquentBuilder $query, mixed $slug): ?int
    {
        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        $id = $query
            ->where('slug', trim($slug))
            ->where('is_active', true)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function stepErrorMessage(): string
    {
        return match ($this->step) {
            1 => "Choose who you're buying for to continue.",
            2 => 'Choose an occasion to continue.',
            3 => 'Choose at least one interest to continue.',
            5 => 'Choose a budget to continue.',
            default => '',
        };
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
            'interest_ids.max' => 'You may select up to '.$this->maxInterests().' interests.',
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
