<?php

namespace App\Livewire;

use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class GiftListing extends Component
{
    /** @var array<string, mixed> */
    #[Locked]
    public array $listingContext = [];

    #[Url(as: 'occasion', history: true, except: '')]
    public string $occasion = '';

    #[Url(as: 'relationship', history: true, except: '')]
    public string $relationship = '';

    #[Url(as: 'recipient', history: true, except: '')]
    public string $recipient = '';

    #[Url(as: 'interest', history: true, except: '')]
    public string $interest = '';

    #[Url(as: 'profession', history: true, except: '')]
    public string $profession = '';

    #[Url(as: 'gift_type', history: true, except: '')]
    public string $giftType = '';

    #[Url(as: 'category', history: true, except: '')]
    public string $category = '';

    #[Url(as: 'budget', history: true, except: '')]
    public string $budget = '';

    #[Url(as: 'sort', history: true, except: '')]
    public string $sort = '';

    #[Url(history: true, except: 1)]
    public int $page = 1;

    public bool $filtersOpen = false;

    /**
     * @param  array<string, mixed>  $context
     */
    public function mount(array $context): void
    {
        $this->listingContext = $context;
    }

    public function toggleFilter(string $dimension, string $slug): void
    {
        $property = $this->propertyFor($dimension);

        if ($property === null) {
            return;
        }

        if ($property === 'budget') {
            $this->budget = $this->budget === $slug ? '' : $slug;
            $this->page = 1;
            $this->redirectToHubIfGiftIdeasBudgetCleared();

            return;
        }

        $current = DiscoveryListingQueryState::decodeList($this->{$property});

        if (in_array($slug, $current, true)) {
            $current = array_values(array_filter($current, fn (string $item): bool => $item !== $slug));
        } else {
            $current[] = $slug;
        }

        $this->{$property} = DiscoveryListingQueryState::encodeList($current);
        $this->page = 1;
    }

    public function removeFilter(string $dimension, string $slug): void
    {
        $this->toggleFilter($dimension, $slug);
    }

    public function clearFilters(): void
    {
        $this->occasion = '';
        $this->relationship = '';
        $this->recipient = '';
        $this->interest = '';
        $this->profession = '';
        $this->giftType = '';
        $this->category = '';
        $this->budget = '';
        $this->sort = '';
        $this->page = 1;
        $this->filtersOpen = false;
        $this->redirectToHubIfGiftIdeasBudgetCleared();
    }

    public function applyFilters(): void
    {
        $this->filtersOpen = false;
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function updatedSort(): void
    {
        $this->page = 1;
    }

    public function render(QueryDiscoveryListingProductsAction $queryListing): View
    {
        $context = $this->listingContext();
        $state = $this->queryState();
        $products = $queryListing->execute($context, $state, $this->page);
        $options = $this->filterOptions($context);
        $activeChips = $this->activeChips($options);
        $activeCount = count($activeChips);

        $lastItem = $products->lastItem();

        return view('livewire.gift-listing', [
            'context' => $context,
            'products' => $products,
            'options' => $options,
            'activeChips' => $activeChips,
            'activeCount' => $activeCount,
            'selected' => $this->selectedSlugs(),
            'finderUrl' => DiscoveryUrl::finder(),
            'giftIdeasUrl' => DiscoveryUrl::giftIdeas(),
            'giftsLabel' => Terminology::gifts(),
            'remaining' => $lastItem === null ? 0 : max(0, $products->total() - (int) $lastItem),
        ]);
    }

    private function listingContext(): DiscoveryListingContext
    {
        return DiscoveryListingContext::fromArray($this->listingContext);
    }

    private function redirectToHubIfGiftIdeasBudgetCleared(): void
    {
        if (($this->listingContext['surface'] ?? '') !== 'gift_ideas') {
            return;
        }

        if ($this->budget !== '') {
            return;
        }

        $this->redirect(DiscoveryUrl::giftIdeas());
    }

    private function queryState(): DiscoveryListingQueryState
    {
        $sort = $this->sort === ''
            ? DiscoveryListingQueryState::SORT_RECOMMENDED
            : $this->sort;

        if (! in_array($sort, DiscoveryListingQueryState::sorts(), true)) {
            $sort = DiscoveryListingQueryState::SORT_RECOMMENDED;
        }

        return (new DiscoveryListingQueryState(
            occasionSlugs: DiscoveryListingQueryState::decodeList($this->occasion),
            relationshipSlugs: DiscoveryListingQueryState::decodeList($this->relationship),
            recipientSlugs: DiscoveryListingQueryState::decodeList($this->recipient),
            interestSlugs: DiscoveryListingQueryState::decodeList($this->interest),
            professionSlugs: DiscoveryListingQueryState::decodeList($this->profession),
            giftTypeSlugs: DiscoveryListingQueryState::decodeList($this->giftType),
            categoryPaths: DiscoveryListingQueryState::decodeList($this->category),
            budgetSlug: $this->budget !== '' ? $this->budget : null,
            sort: $sort,
            page: max(1, $this->page),
        ))->scopedTo($this->listingContext());
    }

    /**
     * @return array<string, Collection<int, object>>
     */
    private function filterOptions(DiscoveryListingContext $context): array
    {
        $options = [];

        if ($context->allows('occasion')) {
            $options['occasion'] = $this->activeTaxonomy(Occasion::query());
        }
        if ($context->allows('relationship')) {
            $options['relationship'] = $this->activeTaxonomy(Relationship::query());
        }
        if ($context->allows('recipient')) {
            $options['recipient'] = $this->activeTaxonomy(RecipientType::query());
        }
        if ($context->allows('profession')) {
            $options['profession'] = $this->activeTaxonomy(Profession::query());
        }
        if ($context->allows('gift_type')) {
            $options['gift_type'] = $this->activeTaxonomy(GiftType::query());
        }
        if ($context->allows('interest')) {
            $hidden = $context->hiddenInterestIds;
            $options['interest'] = $this->activeTaxonomy(Interest::query())
                ->reject(fn ($item) => in_array((int) $item->id, $hidden, true))
                ->values();
        }
        if ($context->allows('category')) {
            $options['category'] = Category::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'full_path']);
        }
        if ($context->allows('budget')) {
            $options['budget'] = BudgetRange::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'slug']);
        }

        return $options;
    }

    private function activeTaxonomy(EloquentBuilder $query): Collection
    {
        return $query
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /**
     * @return array<string, list<string>>
     */
    private function selectedSlugs(): array
    {
        return [
            'occasion' => DiscoveryListingQueryState::decodeList($this->occasion),
            'relationship' => DiscoveryListingQueryState::decodeList($this->relationship),
            'recipient' => DiscoveryListingQueryState::decodeList($this->recipient),
            'interest' => DiscoveryListingQueryState::decodeList($this->interest),
            'profession' => DiscoveryListingQueryState::decodeList($this->profession),
            'gift_type' => DiscoveryListingQueryState::decodeList($this->giftType),
            'category' => DiscoveryListingQueryState::decodeList($this->category),
            'budget' => $this->budget !== '' ? [$this->budget] : [],
        ];
    }

    /**
     * @param  array<string, Collection<int, object>>  $options
     * @return list<array{dimension: string, slug: string, label: string}>
     */
    private function activeChips(array $options): array
    {
        $chips = [];
        $selected = $this->selectedSlugs();

        foreach ($selected as $dimension => $slugs) {
            if ($slugs === [] || ! isset($options[$dimension])) {
                continue;
            }

            foreach ($options[$dimension] as $option) {
                $value = $dimension === 'category'
                    ? (string) $option->full_path
                    : (string) $option->slug;

                if (in_array($value, $slugs, true)) {
                    $chips[] = [
                        'dimension' => $dimension,
                        'slug' => $value,
                        'label' => (string) $option->name,
                    ];
                }
            }
        }

        return $chips;
    }

    private function propertyFor(string $dimension): ?string
    {
        return match ($dimension) {
            'occasion' => 'occasion',
            'relationship' => 'relationship',
            'recipient' => 'recipient',
            'interest' => 'interest',
            'profession' => 'profession',
            'gift_type' => 'giftType',
            'category' => 'category',
            'budget' => 'budget',
            default => null,
        };
    }
}
