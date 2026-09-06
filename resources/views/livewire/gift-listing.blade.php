<div x-data="filterDrawer" x-effect="sync($wire.filtersOpen)">
    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
        <aside class="hidden w-64 shrink-0 lg:block xl:w-72" aria-label="Filters">
            <div class="sticky top-6 space-y-6 rounded-lg border border-line bg-surface p-4" wire:loading.class="pointer-events-none opacity-60">
                <h2 class="text-sm font-semibold text-ink">Filters</h2>
                @include('livewire.partials.gift-listing-filters', ['idPrefix' => 'desktop'])
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-ink-muted" aria-live="polite">
                    {{ number_format($products->total()) }}
                    {{ strtolower($products->total() === 1 ? \Illuminate\Support\Str::singular($giftsLabel) : $giftsLabel) }}
                </p>

                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center rounded-md border border-line bg-surface px-4 py-2 text-sm font-medium text-ink lg:hidden"
                        wire:click="$toggle('filtersOpen')"
                        :aria-expanded="$wire.filtersOpen"
                        aria-controls="gift-listing-filter-drawer"
                    >
                        Filters{{ $activeCount > 0 ? ' · '.$activeCount : '' }}
                    </button>

                    <label class="flex min-h-11 items-center gap-2 text-sm text-ink">
                        <span class="sr-only lg:not-sr-only text-ink-muted">Sort</span>
                        <select
                            id="gift-listing-sort"
                            wire:model.live="sort"
                            wire:loading.attr="disabled"
                            class="min-h-11 rounded-md border border-line bg-surface px-3 text-sm text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                        >
                            <option value="">Recommended</option>
                            <option value="price_asc">Price: Low to High</option>
                            <option value="price_desc">Price: High to Low</option>
                            <option value="newest">Newest</option>
                        </select>
                    </label>
                </div>
            </div>

            @if ($activeChips !== [])
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    @foreach ($activeChips as $chip)
                        <button
                            type="button"
                            wire:click="removeFilter('{{ $chip['dimension'] }}', @js($chip['slug']))"
                            class="inline-flex min-h-11 items-center gap-2 rounded-full border border-line bg-plum-light px-3 text-sm text-plum-dark"
                        >
                            <span>{{ $chip['label'] }}</span>
                            <span aria-hidden="true">×</span>
                            <span class="sr-only">Remove {{ $chip['label'] }} filter</span>
                        </button>
                    @endforeach
                    <button type="button" wire:click="clearFilters" class="inline-flex min-h-11 items-center text-sm font-medium text-plum hover:underline">
                        Clear all
                    </button>
                </div>
            @endif

            <div
                wire:loading.class="opacity-50"
                class="motion-safe:transition-opacity"
                aria-busy="false"
                wire:loading.attr="aria-busy"
            >
                <div wire:loading class="mb-4">
                    <x-gift-listing.skeleton :count="3" class="sm:hidden" />
                </div>

                @if ($products->isEmpty())
                    <x-gift-listing.empty :finder-url="$finderUrl" :has-active-filters="$activeCount > 0" />
                @else
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($products as $product)
                            <x-gift-card :product="$product" :context="$context->browseContext" wire:key="gift-{{ $product->id }}" />
                        @endforeach
                    </div>

                    @if ($products->hasMorePages())
                        <div class="mt-8 flex justify-center">
                            <a
                                href="{{ $products->nextPageUrl() }}"
                                wire:click.prevent="nextPage"
                                class="inline-flex min-h-11 items-center rounded-md border border-line bg-surface px-5 py-2 text-sm font-medium text-plum hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                            >
                                Load more gift ideas
                            </a>
                        </div>
                    @endif
                @endif
            </div>

            <section class="mt-12 rounded-lg border border-line bg-surface px-6 py-8 text-center">
                <p class="font-serif text-2xl text-plum">Still not sure?</p>
                <p class="mt-2 text-sm text-ink-muted">Start Gift Finder for a short, guided recommendation.</p>
                <div class="mt-5">
                    <x-ui.button :href="$finderUrl" variant="primary">
                        Start Gift Finder
                    </x-ui.button>
                </div>
            </section>
        </div>
    </div>

    <div
        class="lg:hidden"
        @keydown.escape.window="$wire.filtersOpen && $wire.set('filtersOpen', false)"
    >
        <div
            x-show="$wire.filtersOpen"
            x-cloak
            x-transition.opacity.duration.150ms
            class="fixed inset-0 z-40 bg-plum-dark/40"
            wire:click="$set('filtersOpen', false)"
        ></div>

        <div
            id="gift-listing-filter-drawer"
            x-show="$wire.filtersOpen"
            x-cloak
            role="dialog"
            aria-modal="true"
            aria-label="Filters"
            class="fixed inset-y-0 right-0 z-50 flex w-full max-w-sm flex-col border-l border-line bg-surface shadow-lg"
        >
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-4">
                <h2 class="text-base font-semibold text-ink">Filters</h2>
                <div class="flex items-center gap-2">
                    @if ($activeCount > 0)
                        <button type="button" wire:click="clearFilters" class="text-sm font-medium text-plum hover:underline">
                            Clear all
                        </button>
                    @endif
                    <button
                        type="button"
                        x-ref="close"
                        wire:click="$set('filtersOpen', false)"
                        aria-label="Close filters"
                        class="inline-flex size-11 items-center justify-center rounded-md text-ink hover:bg-plum-light"
                    >
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
            </div>

            <div class="flex-1 space-y-6 overflow-y-auto px-4 py-4">
                @include('livewire.partials.gift-listing-filters', ['idPrefix' => 'mobile'])
            </div>

            <div class="flex gap-3 border-t border-line px-4 py-4">
                <x-ui.button type="button" variant="secondary" class="flex-1" wire:click="clearFilters">
                    Reset
                </x-ui.button>
                <x-ui.button type="button" variant="primary" class="flex-1" wire:click="applyFilters">
                    Show {{ number_format($products->total()) }} {{ strtolower($products->total() === 1 ? \Illuminate\Support\Str::singular($giftsLabel) : $giftsLabel) }}
                </x-ui.button>
            </div>
        </div>
    </div>
</div>
