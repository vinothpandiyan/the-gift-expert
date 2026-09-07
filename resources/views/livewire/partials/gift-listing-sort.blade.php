@php
    $selectClass = 'h-11 w-full appearance-none rounded-md border border-line bg-surface pr-9 pl-3 text-[14px] font-medium text-ink hover:border-plum/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum';
    $wrapperClass = $wrapperClass ?? '';
    $showLabel = $showLabel ?? false;
@endphp

<div class="relative inline-flex w-full items-center {{ $wrapperClass }}">
    <label for="{{ $id }}" @class(['sr-only text-[13px] text-ink-muted', 'md:not-sr-only md:mr-2' => $showLabel])>Sort</label>
    <select
        id="{{ $id }}"
        wire:model.live="sort"
        wire:loading.attr="disabled"
        class="{{ $selectClass }}"
    >
        <option value="">Recommended</option>
        <option value="price_asc">Price: Low to High</option>
        <option value="price_desc">Price: High to Low</option>
        <option value="newest">Newest</option>
    </select>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="pointer-events-none absolute right-3 size-4 text-ink-muted" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
    </svg>
</div>
