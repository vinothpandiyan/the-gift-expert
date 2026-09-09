<div x-data="filterDrawer" x-effect="sync($wire.filtersOpen)">
    <div class="flex gap-8 lg:gap-10">
        <aside class="hidden w-[264px] shrink-0 lg:block" aria-label="Filters">
            <div class="sticky top-24" wire:loading.class="pointer-events-none opacity-60">
                <div class="flex items-center justify-between pb-2">
                    <h2 class="text-[13px] font-semibold uppercase tracking-[0.12em] text-ink-muted">Narrow it down</h2>
                    @if ($activeCount > 0)
                        <button type="button" wire:click="clearFilters" class="min-h-11 text-[13px] font-medium text-plum hover:underline">
                            Clear all
                        </button>
                    @endif
                </div>
                <div class="rounded-xl border border-line bg-surface px-3">
                    @include('livewire.partials.gift-listing-filters', ['idPrefix' => 'desktop'])
                </div>
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            <div class="flex flex-col gap-3 border-b border-line pb-4 md:flex-row md:items-center md:justify-between">
                <h2 class="text-[14px] font-medium text-ink" aria-live="polite">
                    {{ number_format($products->total()) }}
                    gift {{ $products->total() === 1 ? 'idea' : 'ideas' }}
                </h2>

                <div class="hidden lg:block">
                    @include('livewire.partials.gift-listing-sort', ['id' => 'gift-listing-sort', 'showLabel' => true])
                </div>

                <div class="flex items-center gap-2 lg:hidden">
                    <button
                        type="button"
                        class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-md border border-plum/30 bg-surface px-4 text-sm font-semibold text-plum hover:border-plum hover:bg-plum-light"
                        wire:click="$toggle('filtersOpen')"
                        :aria-expanded="$wire.filtersOpen"
                        aria-expanded="false"
                        aria-controls="gift-listing-filter-drawer"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="size-4" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16M7 12h10M10 19h4" />
                        </svg>
                        Filters{{ $activeCount > 0 ? ' · '.$activeCount : '' }}
                    </button>
                    @include('livewire.partials.gift-listing-sort', ['id' => 'gift-listing-sort-mobile', 'wrapperClass' => 'flex-1'])
                </div>
            </div>

            @if ($activeChips !== [])
                <div class="flex flex-wrap items-center gap-2 pt-4">
                    @foreach ($activeChips as $chip)
                        <button
                            type="button"
                            wire:click="removeFilter('{{ $chip['dimension'] }}', @js($chip['slug']))"
                            class="inline-flex min-h-11 items-center gap-1.5 rounded-sm border border-plum/25 bg-plum-light px-3 text-[13px] font-medium text-plum hover:border-plum"
                        >
                            <span>{{ $chip['label'] }}</span>
                            <span aria-hidden="true">×</span>
                            <span class="sr-only">Remove {{ $chip['label'] }} filter</span>
                        </button>
                    @endforeach
                    <button type="button" wire:click="clearFilters" class="min-h-11 px-1 text-[13px] font-medium text-ink-muted hover:text-plum hover:underline">
                        Clear all
                    </button>
                </div>
            @endif

            <div class="pt-6">
                <div
                    wire:loading
                    wire:target="toggleFilter, setFilter, removeFilter, clearFilters, nextPage, sort"
                    aria-busy="true"
                    aria-live="polite"
                >
                    <x-gift-listing.skeleton :count="6" />
                </div>

                <div
                    wire:loading.remove
                    wire:target="toggleFilter, setFilter, removeFilter, clearFilters, nextPage, sort"
                    class="motion-safe:transition-opacity"
                >
                    @if ($products->isEmpty())
                        <x-gift-listing.empty
                            :finder-url="$finderUrl"
                            :has-active-filters="$activeCount > 0"
                            :last-chip="$activeChips === [] ? null : $activeChips[array_key_last($activeChips)]"
                            :gift-ideas-url="$giftIdeasUrl"
                        />
                    @else
                        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-5 xl:grid-cols-4">
                            @foreach ($products as $product)
                                <x-gift-card :product="$product" :context="$context->browseContext" wire:key="gift-{{ $product->id }}" />
                            @endforeach
                        </div>

                        @if ($products->hasMorePages())
                            <div class="mt-10 flex flex-col items-center gap-2">
                                <x-ui.button
                                    :href="$products->nextPageUrl()"
                                    variant="secondary"
                                    wire:click.prevent="nextPage"
                                    wire:loading.attr="disabled"
                                    wire:target="nextPage"
                                >
                                    Load more gift ideas
                                </x-ui.button>
                                @if ($remaining > 0)
                                    <p class="text-[13px] text-ink-muted">{{ number_format($remaining) }} more to see</p>
                                @endif
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

    <x-finder.promo :finder-url="$finderUrl" class="mt-14" />

    <div
        class="lg:hidden"
        @keydown.escape.window="$wire.filtersOpen && $wire.set('filtersOpen', false)"
        @keydown.tab="if ($wire.filtersOpen) trap($event)"
    >
        <div
            x-show="$wire.filtersOpen"
            x-cloak
            x-transition.opacity.duration.150ms
            class="fixed inset-0 z-[60] bg-ink/40"
            wire:click="$set('filtersOpen', false)"
        ></div>

        <div
            id="gift-listing-filter-drawer"
            x-ref="panel"
            x-show="$wire.filtersOpen"
            x-cloak
            role="dialog"
            aria-modal="true"
            aria-label="Filters"
            tabindex="-1"
            class="fixed inset-x-0 bottom-0 top-10 z-[60] flex flex-col rounded-t-2xl bg-ivory"
        >
            <div class="flex items-center justify-between border-b border-line px-5 py-4">
                <h2 class="font-serif text-xl text-ink">Filters</h2>
                <div class="flex items-center gap-3">
                    @if ($activeCount > 0)
                        <button type="button" wire:click="clearFilters" class="min-h-11 text-[13px] font-medium text-plum">
                            Clear all
                        </button>
                    @endif
                    <button
                        type="button"
                        x-ref="close"
                        wire:click="$set('filtersOpen', false)"
                        aria-label="Close filters"
                        class="inline-flex size-11 items-center justify-center rounded-md border border-line bg-surface text-ink"
                    >
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-5 pb-4">
                @include('livewire.partials.gift-listing-filters', ['idPrefix' => 'mobile'])
            </div>

            <div class="flex items-center gap-3 border-t border-line bg-surface px-5 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                <x-ui.button type="button" variant="secondary" class="flex-1" wire:click="clearFilters">
                    Reset
                </x-ui.button>
                <x-ui.button type="button" variant="primary" class="flex-[1.6]" wire:click="applyFilters">
                    Show {{ number_format($products->total()) }} {{ strtolower($products->total() === 1 ? \Illuminate\Support\Str::singular($giftsLabel) : $giftsLabel) }}
                </x-ui.button>
            </div>
        </div>
    </div>
</div>
