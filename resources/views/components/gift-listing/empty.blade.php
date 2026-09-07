@props([
    'finderUrl',
    'hasActiveFilters' => false,
    'lastChip' => null,
    'giftIdeasUrl' => null,
])

<div class="rounded-xl border border-dashed border-line bg-surface px-6 py-14 text-center">
    <h2 class="font-serif text-2xl text-ink">
        @if ($hasActiveFilters)
            No gift ideas match all those filters.
        @else
            No {{ strtolower(\App\Support\Terminology::gifts()) }} found yet.
        @endif
    </h2>
    @if ($hasActiveFilters)
        <p class="mx-auto mt-2 max-w-md text-[15px] text-ink-muted">
            Try removing one filter or increasing the budget — most gifts sit in one or two categories only.
        </p>
    @endif
    <div class="mt-6 flex flex-wrap justify-center gap-3">
        @if ($hasActiveFilters && is_array($lastChip) && filled($lastChip['label'] ?? null))
            <x-ui.button type="button" variant="secondary" wire:click="removeFilter('{{ $lastChip['dimension'] }}', @js($lastChip['slug']))">
                Remove “{{ $lastChip['label'] }}”
            </x-ui.button>
        @endif
        @if ($hasActiveFilters)
            <x-ui.button type="button" variant="secondary" wire:click="clearFilters">
                Clear all filters
            </x-ui.button>
        @endif
        @if (filled($giftIdeasUrl))
            <x-ui.button :href="$giftIdeasUrl" variant="secondary">
                Browse Gift Ideas
            </x-ui.button>
        @endif
        <x-ui.button :href="$finderUrl" variant="primary">
            Try Gift Finder
        </x-ui.button>
    </div>
</div>
