<div x-data="filterDrawer" x-effect="sync($wire.filtersOpen)">
    <div class="flex gap-8 lg:gap-10">
        <aside class="hidden w-[240px] shrink-0 md:block lg:w-[264px]" aria-label="Filters">
            <div class="sticky top-24" wire:loading.class="pointer-events-none opacity-60">
                <div class="flex items-center justify-between pb-2">
                    <h2 class="text-[13px] font-semibold uppercase tracking-[0.12em] text-ink-muted">Narrow it down</h2>
                    @if ($activeCount > 0)
                        <button type="button" wire:click="clearFilters" class="text-[13px] font-medium text-plum hover:underline">
                            Clear all
                        </button>
                    @endif
                </div>
                <div class="rounded-[14px] border border-line bg-surface px-4">
                    @include('livewire.partials.gift-listing-filters', ['idPrefix' => 'desktop'])
                </div>
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            <div class="flex flex-col gap-3 border-b border-line pb-4 md:flex-row md:items-center md:justify-between">
                <p class="text-[14px] font-medium text-ink" aria-live="polite">
                    {{ number_format($products->total()) }}
                    gift {{ $products->total() === 1 ? 'idea' : 'ideas' }}
                </p>

                <div class="hidden md:block">
                    @include('livewire.partials.gift-listing-sort', ['id' => 'gift-listing-sort', 'showLabel' => true])
                </div>

                <div class="flex items-center gap-2 md:hidden">
                    <button
                        type="button"
                        class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-[10px] border border-plum/30 bg-surface px-4 text-sm font-semibold text-plum hover:border-plum hover:bg-plum-light"
                        wire:click="$toggle('filtersOpen')"
                        :aria-expanded="$wire.filtersOpen"
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
                            class="inline-flex min-h-9 items-center gap-1.5 rounded-[8px] border border-plum/25 bg-plum-light px-3 text-[13px] font-medium text-plum hover:border-plum"
                        >
                            <span>{{ $chip['label'] }}</span>
                            <span aria-hidden="true">×</span>
                            <span class="sr-only">Remove {{ $chip['label'] }} filter</span>
                        </button>
                    @endforeach
                    <button type="button" wire:click="clearFilters" class="min-h-9 px-1 text-[13px] font-medium text-ink-muted hover:text-plum hover:underline">
                        Clear all
                    </button>
                </div>
            @endif

            <div class="pt-6">
                <div wire:loading wire:target="toggleFilter, removeFilter, clearFilters, nextPage, sort">
                    <x-gift-listing.skeleton :count="6" />
                </div>

                <div
                    wire:loading.remove
                    wire:target="toggleFilter, removeFilter, clearFilters, nextPage, sort"
                    class="motion-safe:transition-opacity"
                    aria-busy="false"
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

    <section class="mt-14 flex flex-col gap-5 rounded-[14px] bg-plum px-6 py-8 text-white md:flex-row md:items-center md:justify-between md:px-10 md:py-10">
        <div class="max-w-xl">
            <p class="mb-2 inline-flex items-center gap-2 text-[12px] font-semibold uppercase tracking-[0.12em] text-white/80">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-3.5" aria-hidden="true">
                    <path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                </svg>
                Gift Finder
            </p>
            <h2 class="font-serif text-2xl md:text-3xl">Still not sure what they'd like?</h2>
            <p class="mt-2 text-[15px] text-white/85">Answer a few questions and we'll narrow it down to a handful of ideas.</p>
        </div>
        <x-ui.button :href="$finderUrl" variant="coral" class="h-14 w-full shrink-0 px-7 text-base md:w-auto">
            Start Gift Finder
        </x-ui.button>
    </section>

    <div
        class="md:hidden"
        @keydown.escape.window="$wire.filtersOpen && $wire.set('filtersOpen', false)"
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
            x-show="$wire.filtersOpen"
            x-cloak
            role="dialog"
            aria-modal="true"
            aria-label="Filters"
            class="fixed inset-x-0 bottom-0 top-10 z-[60] flex flex-col rounded-t-[16px] bg-ivory"
        >
            <div class="flex items-center justify-between border-b border-line px-5 py-4">
                <h2 class="font-serif text-xl text-ink">Filters</h2>
                <div class="flex items-center gap-3">
                    @if ($activeCount > 0)
                        <button type="button" wire:click="clearFilters" class="text-[13px] font-medium text-plum">
                            Clear all
                        </button>
                    @endif
                    <button
                        type="button"
                        x-ref="close"
                        wire:click="$set('filtersOpen', false)"
                        aria-label="Close filters"
                        class="inline-flex size-11 items-center justify-center rounded-[10px] border border-line bg-surface text-ink"
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
