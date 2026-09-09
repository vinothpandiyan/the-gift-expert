<?php

namespace App\Livewire;

use App\Actions\Discovery\NormalizeDiscoveryFilterStateAction;
use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\Actions\Discovery\ResolveDiscoveryFilterOptionsAction;
use App\DiscoveryListing\DiscoveryFilterOption;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Contracts\View\View;
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

    #[Locked]
    public ?int $loadedFromPage = null;

    #[Locked]
    public string $listingUrl = '';

    public bool $filtersOpen = false;

    public bool $loadMoreFailed = false;

    /**
     * @param  array<string, mixed>  $context
     */
    public function mount(array $context): void
    {
        $this->listingContext = $context;
        $this->listingUrl = request()->url();
    }

    public function toggleFilter(string $dimension, string $slug): void
    {
        $property = $this->propertyFor($dimension);

        if ($property === null) {
            return;
        }

        if ($property === 'budget') {
            $this->applyFilter($dimension, $slug, $this->budget !== $slug);

            return;
        }

        $current = DiscoveryListingQueryState::decodeList($this->{$property});
        $this->applyFilter($dimension, $slug, ! in_array($slug, $current, true));
    }

    public function setFilter(string $dimension, string $slug, bool $enabled): void
    {
        $this->applyFilter($dimension, $slug, $enabled);
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
        $this->resetLoadedWindow();
        $this->filtersOpen = false;
        $this->redirectToHubIfGiftIdeasBudgetCleared();
    }

    public function applyFilters(): void
    {
        $this->filtersOpen = false;
    }

    public function nextPage(): void
    {
        $this->loadMoreFailed = false;
        $this->loadedFromPage ??= max(1, $this->page);
        $this->page = max($this->page, $this->loadedFromPage) + 1;
    }

    public function markLoadMoreFailed(): void
    {
        $this->loadMoreFailed = true;
    }

    public function updatedSort(): void
    {
        $this->resetLoadedWindow();
    }

    public function render(
        QueryDiscoveryListingProductsAction $queryListing,
        ResolveDiscoveryFilterOptionsAction $resolveOptions,
    ): View {
        $this->syncNormalizedFilters();
        $context = $this->listingContext();
        $state = $this->queryState();
        $fromPage = max(1, $this->loadedFromPage ?? $this->page);
        $throughPage = max($this->page, $fromPage);
        $products = $queryListing->execute($context, $state, $fromPage, $throughPage);

        if ($this->listingUrl !== '') {
            $products->withPath($this->listingUrl);
        }
        $options = $resolveOptions->execute($context, $state);
        $activeChips = $this->activeChips($options);
        $activeCount = count($activeChips);
        $loadedCount = ($fromPage - 1) * $products->perPage() + $products->count();
        $remaining = max(0, $products->total() - $loadedCount);
        $resultsHeading = $products->isEmpty()
            ? '0 gift ideas'
            : ($loadedCount < $products->total()
                ? 'Showing '.number_format($loadedCount).' of '.number_format($products->total()).' gift '.($products->total() === 1 ? 'idea' : 'ideas')
                : number_format($products->total()).' gift '.($products->total() === 1 ? 'idea' : 'ideas'));

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
            'remaining' => $remaining,
            'resultsHeading' => $resultsHeading,
        ]);
    }

    private function listingContext(): DiscoveryListingContext
    {
        return DiscoveryListingContext::fromArray($this->listingContext);
    }

    private function syncNormalizedFilters(?string $preferredDimension = null): void
    {
        $before = $this->queryState()->toPaginatorQuery();
        $normalized = app(NormalizeDiscoveryFilterStateAction::class)
            ->execute($this->listingContext(), $this->queryState(), $preferredDimension);

        $this->occasion = DiscoveryListingQueryState::encodeList($normalized->occasionSlugs);
        $this->relationship = DiscoveryListingQueryState::encodeList($normalized->relationshipSlugs);
        $this->recipient = DiscoveryListingQueryState::encodeList($normalized->recipientSlugs);
        $this->interest = DiscoveryListingQueryState::encodeList($normalized->interestSlugs);
        $this->profession = DiscoveryListingQueryState::encodeList($normalized->professionSlugs);
        $this->giftType = DiscoveryListingQueryState::encodeList($normalized->giftTypeSlugs);
        $this->category = DiscoveryListingQueryState::encodeList($normalized->categoryPaths);
        $this->budget = $normalized->budgetSlug ?? '';

        if ($before !== $this->queryState()->toPaginatorQuery()) {
            $this->resetLoadedWindow();
        }
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
     * @param  array<string, Collection<int, DiscoveryFilterOption>>  $options
     * @return list<array{dimension: string, slug: string, label: string}>
     */
    private function activeChips(array $options): array
    {
        $chips = [];

        foreach ($options as $dimension => $dimensionOptions) {
            foreach ($dimensionOptions as $option) {
                if (! $option->selected) {
                    continue;
                }

                $chips[] = [
                    'dimension' => $dimension,
                    'slug' => $option->slug,
                    'label' => $option->label,
                ];
            }
        }

        return $chips;
    }

    private function applyFilter(string $dimension, string $slug, bool $enabled): void
    {
        $property = $this->propertyFor($dimension);

        if ($property === null) {
            return;
        }

        if ($property === 'budget') {
            $this->budget = $enabled ? $slug : ($this->budget === $slug ? '' : $this->budget);
            $this->resetLoadedWindow();
            $this->syncNormalizedFilters();
            $this->redirectToHubIfGiftIdeasBudgetCleared();

            return;
        }

        $current = DiscoveryListingQueryState::decodeList($this->{$property});
        $isSelected = in_array($slug, $current, true);

        if ($enabled && ! $isSelected) {
            $current[] = $slug;
        } elseif (! $enabled && $isSelected) {
            $current = array_values(array_filter($current, fn (string $item): bool => $item !== $slug));
        } else {
            return;
        }

        $this->{$property} = DiscoveryListingQueryState::encodeList($current);
        $this->resetLoadedWindow();
        $this->syncNormalizedFilters($dimension);
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

    private function resetLoadedWindow(): void
    {
        $this->page = 1;
        $this->loadedFromPage = null;
        $this->loadMoreFailed = false;
    }
}
