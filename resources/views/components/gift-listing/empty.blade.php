@props([
    'finderUrl',
    'hasActiveFilters' => false,
])

<div class="rounded-lg border border-dashed border-line bg-surface px-6 py-12 text-center">
    <p class="text-lg font-medium text-ink">
        @if ($hasActiveFilters)
            No gift ideas match all those filters.
        @else
            No {{ strtolower(\App\Support\Terminology::gifts()) }} found yet.
        @endif
    </p>
    @if ($hasActiveFilters)
        <p class="mt-2 text-sm text-ink-muted">
            Try removing a filter or choosing a different budget.
        </p>
        <div class="mt-6">
            <x-ui.button type="button" variant="secondary" wire:click="clearFilters">
                Clear filters
            </x-ui.button>
        </div>
    @endif
    <p class="mt-8 text-sm text-ink-muted">Still stuck?</p>
    <div class="mt-3">
        <x-ui.button :href="$finderUrl" variant="primary">
            Try Gift Finder
        </x-ui.button>
    </div>
</div>
