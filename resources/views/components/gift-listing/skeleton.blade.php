@props([
    'count' => 6,
])

<div {{ $attributes->merge(['class' => 'grid grid-cols-2 gap-4 md:gap-5 lg:grid-cols-3 2xl:grid-cols-4']) }} aria-hidden="true">
    @foreach (range(1, $count) as $item)
        <div class="overflow-hidden rounded-xl border border-line bg-surface">
            <div class="aspect-square animate-pulse bg-surface-sunken"></div>
            <div class="space-y-3 px-3 pt-3 pb-3">
                <div class="h-4 w-3/4 animate-pulse rounded bg-surface-sunken"></div>
                <div class="h-3 w-full animate-pulse rounded bg-surface-sunken"></div>
                <div class="h-3 w-1/2 animate-pulse rounded bg-surface-sunken"></div>
            </div>
        </div>
    @endforeach
</div>
