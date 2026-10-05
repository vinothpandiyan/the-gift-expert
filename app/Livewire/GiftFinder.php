<?php

namespace App\Livewire;

use App\Actions\Recommendation\GenerateRecommendationsAction;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Models\BudgetRange;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientGender;
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
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Four-step Gift Finder: recipient, occasion, budget, optional interests.
 *
 * The `*_id` properties are the source of truth. The slug properties
 * (`relationship`, `occasion`, `budget`, `interest`, `gender`) mirror them into the
 * URL so a refresh or homepage hand-off restores the answers. "Any budget" is
 * `any_budget = true` with a null `budget_range_id` (no taxonomy row); it is written
 * to the URL as the explicit flag `budget_any=1`, never as a budget slug, so an
 * unanswered budget (no `budget`, no flag) stays distinguishable. "No interests" is
 * an empty list.
 *
 * Browser history: only `step` pushes an entry, so Back/Forward follow wizard
 * navigation (Continue, Back, editing an earlier step). Answer properties replace the
 * current entry, so picking an answer or toggling interests adds no Back presses.
 * Replace-mode properties are not restored by Livewire on popstate, so the view
 * re-applies them from the URL (see URL_ANSWER_PROPERTIES and gift-finder.blade.php).
 *
 * Profession, gift type and recipient type are no longer asked, but are kept so older
 * `?session=` links round-trip without silently dropping answers.
 *
 * Gender is also no longer asked, but it is a hard result filter, so a gender carried
 * over from a legacy session is deliberately kept: it is mirrored into the URL as
 * `gender=male|female`, shown in the summary, and stays until the user removes it.
 * There is no gender wizard step, and nothing sets gender except a legacy session or a
 * valid `gender` URL value.
 */
class GiftFinder extends Component
{
    public const LAST_STEP = 4;

    /**
     * URL-mirrored answer properties (aliases match the property names). The view
     * re-applies these from the URL on browser Back/Forward.
     *
     * @var list<string>
     */
    public const URL_ANSWER_PROPERTIES = ['relationship', 'occasion', 'budget', 'budget_any', 'interest', 'gender'];

    #[Url(history: true, except: 1)]
    public int $step = 1;

    public mixed $occasion_id = null;

    public mixed $relationship_id = null;

    public mixed $recipient_type_id = null;

    public mixed $recipient_gender_id = null;

    /** @var list<int|string> */
    public array $interest_ids = [];

    public mixed $profession_id = null;

    public mixed $gift_type_id = null;

    public mixed $budget_range_id = null;

    /** The user explicitly chose "Any budget" (no price constraint). */
    public bool $any_budget = false;

    /** An earlier answer is being changed from the summary; return to the furthest step afterwards. */
    public bool $editing = false;

    #[Url(as: 'relationship', history: false, except: '')]
    public string $relationship = '';

    #[Url(as: 'occasion', history: false, except: '')]
    public string $occasion = '';

    #[Url(as: 'budget', history: false, except: '')]
    public string $budget = '';

    /** `1` when "Any budget" was explicitly chosen; empty otherwise. Never a taxonomy slug. */
    #[Url(as: 'budget_any', history: false, except: '')]
    public string $budget_any = '';

    #[Url(as: 'interest', history: false, except: '')]
    public string $interest = '';

    /** Legacy gender carried over from an old session (`male` / `female`). Not a wizard step. */
    #[Url(as: 'gender', history: false, except: '')]
    public string $gender = '';

    /**
     * Legacy `?session=` link. It is consumed on first load and then dropped from the
     * URL, so from that point the URL slugs own the Finder state: an absent
     * `interest` means "no interests", never "restore the saved ones".
     */
    #[Url(as: 'session', history: false, except: '')]
    public string $session = '';

    public ?string $stepError = null;

    public bool $finding = false;

    public function mount(?string $session = null): void
    {
        $uuid = is_string($session) && $session !== ''
            ? $session
            : request()->query('session');

        // A `step` in the URL is only ever written by the wizard itself, so a URL that
        // carries both has already taken ownership; the saved session is then stale.
        $urlOwnsState = ! (is_string($session) && $session !== '') && request()->query->has('step');

        if (is_string($uuid) && $uuid !== '' && ! $urlOwnsState) {
            $this->hydrateFromSession($uuid);
        }

        $this->session = '';
        $this->hydrateFromQuery();
        $this->step = $this->resolveInitialStep();
        $this->syncUrlState();
    }

    public function selectRelationship(int $id): void
    {
        if (! $this->relationships->contains('id', $id)) {
            return;
        }

        $this->relationship_id = $id;
        $this->syncUrlState();
        $this->afterSingleChoice();
    }

    public function selectOccasion(int $id): void
    {
        if (! $this->occasions->contains('id', $id)) {
            return;
        }

        $this->occasion_id = $id;
        $this->syncUrlState();
        $this->afterSingleChoice();
    }

    public function selectBudget(int $id): void
    {
        if (! $this->budgetRanges->contains('id', $id)) {
            return;
        }

        $this->budget_range_id = $id;
        $this->any_budget = false;
        $this->syncUrlState();
        $this->afterSingleChoice();
    }

    public function selectAnyBudget(): void
    {
        $this->budget_range_id = null;
        $this->any_budget = true;
        $this->syncUrlState();
        $this->afterSingleChoice();
    }

    public function toggleInterest(int $id): void
    {
        if (! $this->interests->contains('id', $id)) {
            return;
        }

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
            $this->syncUrlState();

            return;
        }

        if ($ids->count() >= $this->maxInterests()) {
            $this->stepError = 'You can choose up to '.$this->maxInterests().' interests. Deselect one to add another.';

            return;
        }

        $this->interest_ids = $ids->push($id)->all();
        $this->stepError = null;
        $this->syncUrlState();
    }

    /**
     * Removes a legacy answer (carried over from an older session) that is no longer asked.
     */
    public function clearAnswer(string $property): void
    {
        if (! in_array($property, ['recipient_type_id', 'recipient_gender_id', 'profession_id', 'gift_type_id'], true)) {
            return;
        }

        $this->{$property} = null;
        $this->syncUrlState();
    }

    public function back(): void
    {
        $this->stepError = null;
        $this->editing = false;
        $this->step = max(1, $this->step - 1);
        $this->dispatch('finder-step-changed');
    }

    public function continueStep(): void
    {
        if (! $this->canContinue()) {
            $this->stepError = $this->stepErrorMessage();

            return;
        }

        $this->stepError = null;
        $this->editing = false;
        $this->step = min(self::LAST_STEP, $this->step + 1);
        $this->dispatch('finder-step-changed');
    }

    /**
     * Jump to an already-reachable step from the summary without clearing later answers.
     */
    public function editStep(int $step): void
    {
        $reachable = $this->reachableStep();

        if ($step < 1 || $step > $reachable) {
            return;
        }

        $this->stepError = null;
        $this->editing = $step < $reachable && $step < self::LAST_STEP;
        $this->step = $step;
        $this->dispatch('finder-step-changed');
    }

    /**
     * Browser Back/Forward restores `step` before the answer properties, so the step is
     * clamped against the final answers in normalizeState() (called from render()).
     */
    public function updatedStep(): void
    {
        $this->editing = false;
    }

    /**
     * The updated hooks below only turn a URL slug back into its id. They must not
     * re-sync the other slugs: a history pop applies several properties in one batch,
     * in arbitrary order, and syncing between them would clobber a pending value.
     * render() canonicalizes every slug once all updates are applied.
     */
    public function updatedRelationship(): void
    {
        $this->relationship_id = $this->idFromSlug($this->relationships, $this->relationship);
    }

    public function updatedOccasion(): void
    {
        $this->occasion_id = $this->idFromSlug($this->occasions, $this->occasion);
    }

    public function updatedBudget(): void
    {
        $this->applyBudgetFromUrl();
    }

    public function updatedBudgetAny(): void
    {
        $this->applyBudgetFromUrl();
    }

    public function updatedInterest(): void
    {
        $this->interest_ids = $this->idsFromSlugs($this->interest);
    }

    public function updatedGender(): void
    {
        $this->recipient_gender_id = $this->genderIdFromSlug($this->gender);
    }

    public function submit(GenerateRecommendationsAction $action): void
    {
        if ($this->finding) {
            return;
        }

        $this->finding = true;

        $missing = $this->firstUnansweredStep();

        if ($this->step === self::LAST_STEP && $missing !== null) {
            $this->finding = false;
            $this->step = $missing;
            $this->stepError = $this->stepErrorMessage();

            return;
        }

        try {
            $validated = $this->validate($this->rules());
        } catch (ValidationException $exception) {
            $this->finding = false;

            throw $exception;
        }

        $interestIds = collect($validated['interest_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $session = $action->execute([
            'occasion_id' => $this->nullableId($validated['occasion_id'] ?? null),
            'relationship_id' => $this->nullableId($validated['relationship_id'] ?? null),
            'recipient_type_id' => $this->nullableId($validated['recipient_type_id'] ?? null),
            'recipient_gender_id' => $this->nullableId($validated['recipient_gender_id'] ?? null),
            'profession_id' => $this->nullableId($validated['profession_id'] ?? null),
            'gift_type_id' => $this->nullableId($validated['gift_type_id'] ?? null),
            'budget_range_id' => $this->nullableId($validated['budget_range_id'] ?? null),
            'interest_ids' => $interestIds,
        ]);

        $this->redirect(DiscoveryUrl::finderResults($session->uuid));
    }

    public function render(): View
    {
        $this->normalizeState();

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
            3 => $this->budgetAnswered(),
            4 => true,
            default => false,
        };
    }

    /**
     * @return Collection<int, object{id: int, name: string, slug: string, description?: string|null}>
     */
    #[Computed]
    public function relationships(): Collection
    {
        return $this->activeOptions(Relationship::query(), ['id', 'name', 'slug', 'description']);
    }

    /**
     * @return Collection<int, object{id: int, name: string, slug: string, description?: string|null}>
     */
    #[Computed]
    public function occasions(): Collection
    {
        return $this->activeOptions(Occasion::query(), ['id', 'name', 'slug', 'description']);
    }

    /**
     * @return Collection<int, object{id: int, name: string, slug: string}>
     */
    #[Computed]
    public function interests(): Collection
    {
        return $this->activeOptions(Interest::query(), ['id', 'name', 'slug']);
    }

    /**
     * @return Collection<int, object{id: int, name: string, slug: string}>
     */
    #[Computed]
    public function budgetRanges(): Collection
    {
        return $this->activeOptions(BudgetRange::query(), ['id', 'name', 'slug']);
    }

    /**
     * Compact answers for the editable summary.
     *
     * @return list<array{label: string, value: string, step: int|null, change: string, clear: string|null}>
     */
    public function summaryItems(): array
    {
        $items = [];

        $relationship = $this->relationships->firstWhere('id', $this->nullableId($this->relationship_id));
        if ($relationship !== null) {
            $items[] = [
                'label' => 'For',
                'value' => $relationship->name,
                'step' => 1,
                'change' => "Change who you're buying for",
                'clear' => null,
            ];
        }

        $occasion = $this->occasions->firstWhere('id', $this->nullableId($this->occasion_id));
        if ($occasion !== null) {
            $items[] = [
                'label' => 'Occasion',
                'value' => $occasion->name,
                'step' => 2,
                'change' => 'Change the occasion',
                'clear' => null,
            ];
        }

        $budget = $this->budgetRanges->firstWhere('id', $this->nullableId($this->budget_range_id));
        if ($budget !== null || $this->any_budget) {
            $items[] = [
                'label' => 'Budget',
                'value' => $budget?->name ?? 'Any budget',
                'step' => 3,
                'change' => 'Change the budget',
                'clear' => null,
            ];
        }

        $interestNames = $this->interests
            ->whereIn('id', collect($this->interest_ids)->map(fn ($id) => (int) $id)->all())
            ->pluck('name');

        if ($interestNames->isNotEmpty()) {
            $items[] = [
                'label' => 'Into',
                'value' => $interestNames->count() > 2
                    ? $interestNames->take(2)->implode(', ').' +'.($interestNames->count() - 2)
                    : $interestNames->implode(', '),
                'step' => 4,
                'change' => 'Change interests',
                'clear' => null,
            ];
        }

        $legacy = [
            'recipient_type_id' => ['Recipient', RecipientType::class],
            'recipient_gender_id' => ['Gender', RecipientGender::class],
            'profession_id' => ['Profession', Profession::class],
            'gift_type_id' => ['Gift type', GiftType::class],
        ];

        foreach ($legacy as $property => [$label, $model]) {
            $id = $this->nullableId($this->{$property});

            if ($id === null) {
                continue;
            }

            $name = $model::query()->whereKey($id)->value('name');

            if ($name !== null) {
                $items[] = [
                    'label' => $label,
                    'value' => (string) $name,
                    'step' => null,
                    'change' => 'Remove '.strtolower($label),
                    'clear' => $property,
                ];
            }
        }

        return $items;
    }

    public function stepLabel(): string
    {
        return match ($this->step) {
            1 => 'Recipient',
            2 => 'Occasion',
            3 => 'Budget',
            4 => 'Interests',
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
     * First required step that has no answer yet, or null when the core answers are complete.
     */
    public function firstUnansweredStep(): ?int
    {
        return match (true) {
            $this->nullableId($this->relationship_id) === null => 1,
            $this->nullableId($this->occasion_id) === null => 2,
            ! $this->budgetAnswered() => 3,
            default => null,
        };
    }

    /**
     * Furthest step the user may be on given the answers so far.
     */
    public function reachableStep(): int
    {
        return $this->firstUnansweredStep() ?? self::LAST_STEP;
    }

    /**
     * Applied after every update batch (including history pops): keep the step within
     * what the answers allow, and treat Interests-without-budget as "Any budget".
     */
    private function normalizeState(): void
    {
        $this->step = max(1, min($this->step, self::LAST_STEP));

        if (
            $this->step >= self::LAST_STEP
            && $this->nullableId($this->budget_range_id) === null
            && $this->nullableId($this->relationship_id) !== null
            && $this->nullableId($this->occasion_id) !== null
        ) {
            $this->any_budget = true;
        }

        $this->step = min($this->step, $this->reachableStep());

        $this->syncUrlState();
    }

    /**
     * A real budget slug wins; otherwise "Any budget" is the explicit `budget_any=1` flag.
     */
    private function applyBudgetFromUrl(): void
    {
        $this->budget_range_id = $this->idFromSlug($this->budgetRanges, $this->budget);
        $this->any_budget = $this->budget_range_id === null && $this->budget_any === '1';
    }

    private function budgetAnswered(): bool
    {
        return $this->nullableId($this->budget_range_id) !== null || $this->any_budget;
    }

    private function afterSingleChoice(): void
    {
        $this->stepError = null;

        if ($this->editing) {
            $this->editing = false;
            $this->step = $this->reachableStep();
            $this->dispatch('finder-step-changed');
        }
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
            'recipient_gender_id' => [
                'nullable',
                Rule::exists('recipient_genders', 'id')
                    ->where(fn (QueryBuilder $query) => $query
                        ->where('is_active', true)
                        ->whereIn('slug', [RecipientGender::SLUG_MALE, RecipientGender::SLUG_FEMALE])
                        ->whereNull('deleted_at')),
            ],
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

        $this->relationship_id = $this->activeId($this->relationships, $session->relationship_id);
        $this->occasion_id = $this->activeId($this->occasions, $session->occasion_id);
        $this->budget_range_id = $this->activeId($this->budgetRanges, $session->budget_range_id);
        // A saved session was submitted, so a null budget means "Any budget".
        $this->any_budget = $this->budget_range_id === null;
        $this->interest_ids = $this->interests
            ->whereIn('id', $session->interests->pluck('id')->map(fn ($id) => (int) $id)->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->take($this->maxInterests())
            ->values()
            ->all();

        $this->recipient_type_id = $this->activeLegacyId(RecipientType::query(), $session->recipient_type_id);
        $this->recipient_gender_id = $this->activeLegacyId(
            RecipientGender::query()->whereIn('slug', [RecipientGender::SLUG_MALE, RecipientGender::SLUG_FEMALE]),
            $session->recipient_gender_id,
        );
        $this->profession_id = $this->activeLegacyId(Profession::query(), $session->profession_id);
        $this->gift_type_id = $this->activeLegacyId(GiftType::query(), $session->gift_type_id);
    }

    /**
     * Valid, active slugs from the URL override saved answers; anything invalid is ignored.
     */
    private function hydrateFromQuery(): void
    {
        $relationshipId = $this->idFromSlug($this->relationships, request()->query('relationship'));
        if ($relationshipId !== null) {
            $this->relationship_id = $relationshipId;
        }

        $occasionId = $this->idFromSlug($this->occasions, request()->query('occasion'));
        if ($occasionId !== null) {
            $this->occasion_id = $occasionId;
        }

        $budgetId = $this->idFromSlug($this->budgetRanges, request()->query('budget'));
        if ($budgetId !== null) {
            $this->budget_range_id = $budgetId;
            $this->any_budget = false;
        } elseif (request()->query('budget_any') === '1') {
            $this->budget_range_id = null;
            $this->any_budget = true;
        }

        $interestIds = $this->idsFromSlugs(request()->query('interest'));
        if ($interestIds !== []) {
            $this->interest_ids = $interestIds;
        }

        $genderId = $this->genderIdFromSlug(request()->query('gender'));
        if ($genderId !== null) {
            $this->recipient_gender_id = $genderId;
        }
    }

    /**
     * Opens on the furthest useful step: the first unanswered required question,
     * or Interests once recipient, occasion and budget are known. A `step` in the
     * URL (refresh / browser Back) is honoured when it is not ahead of the answers.
     */
    private function resolveInitialStep(): int
    {
        $requested = request()->query('step');
        $requested = is_string($requested) && ctype_digit($requested) ? (int) $requested : null;

        if (
            $requested !== null
            && $requested >= self::LAST_STEP
            && $this->nullableId($this->budget_range_id) === null
            && $this->nullableId($this->relationship_id) !== null
            && $this->nullableId($this->occasion_id) !== null
        ) {
            // Reaching Interests without a budget in the URL means "Any budget".
            $this->any_budget = true;
        }

        $reachable = $this->reachableStep();

        return $requested !== null && $requested >= 1 && $requested <= $reachable
            ? $requested
            : $reachable;
    }

    /**
     * Mirror the answers into the URL slug properties (absence means no constraint).
     */
    private function syncUrlState(): void
    {
        $this->relationship = (string) ($this->relationships->firstWhere('id', $this->nullableId($this->relationship_id))?->slug ?? '');
        $this->occasion = (string) ($this->occasions->firstWhere('id', $this->nullableId($this->occasion_id))?->slug ?? '');
        $this->budget = (string) ($this->budgetRanges->firstWhere('id', $this->nullableId($this->budget_range_id))?->slug ?? '');
        $this->interest = DiscoveryListingQueryState::encodeList(
            $this->interests
                ->whereIn('id', collect($this->interest_ids)->map(fn ($id) => (int) $id)->all())
                ->pluck('slug')
                ->all(),
        );
        $this->budget_any = $this->any_budget && $this->nullableId($this->budget_range_id) === null ? '1' : '';

        $genderId = $this->nullableId($this->recipient_gender_id);
        $this->gender = $genderId === null
            ? ''
            : (string) RecipientGender::query()->whereKey($genderId)->value('slug');
    }

    /**
     * Only the active male / female rows are valid legacy genders (never unisex).
     */
    private function genderIdFromSlug(mixed $slug): ?int
    {
        if (! is_string($slug) || ! in_array($slug, [RecipientGender::SLUG_MALE, RecipientGender::SLUG_FEMALE], true)) {
            return null;
        }

        $id = RecipientGender::query()->where('is_active', true)->where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  Collection<int, object{id: int, slug: string}>  $options
     */
    private function idFromSlug(Collection $options, mixed $slug): ?int
    {
        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        $id = $options->firstWhere('slug', trim($slug))?->id;

        return $id === null ? null : (int) $id;
    }

    /**
     * @return list<int>
     */
    private function idsFromSlugs(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $slugs = DiscoveryListingQueryState::decodeList($value);

        return $this->interests
            ->filter(fn ($interest) => in_array($interest->slug, $slugs, true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->take($this->maxInterests())
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, object{id: int}>  $options
     */
    private function activeId(Collection $options, mixed $id): ?int
    {
        $id = $this->nullableId($id);

        return $id !== null && $options->contains('id', $id) ? $id : null;
    }

    private function activeLegacyId(EloquentBuilder $query, mixed $id): ?int
    {
        $id = $this->nullableId($id);

        if ($id === null) {
            return null;
        }

        return $query->where('is_active', true)->whereKey($id)->exists() ? $id : null;
    }

    private function stepErrorMessage(): string
    {
        return match ($this->step) {
            1 => "Choose who you're buying for to continue.",
            2 => 'Choose an occasion to continue.',
            3 => 'Choose a budget, or Any budget, to continue.',
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
            'recipient_gender_id' => 'recipient gender',
            'profession_id' => 'profession',
            'gift_type_id' => 'gift type',
            'budget_range_id' => 'budget',
            'interest_ids' => 'interests',
            'interest_ids.*' => 'interest',
        ];
    }
}
