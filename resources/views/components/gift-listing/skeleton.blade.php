@props([
    'count' => 6,
])

<div {{ $attributes->merge(['class' => 'grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-5 xl:grid-cols-4']) }} aria-hidden="true">
    @foreach (range(1, $count) as $item)
        <div class="rounded-xl border border-line bg-surface p-3">
            <div class="aspect-square animate-pulse rounded-md bg-surface-sunken"></div>
            <div class="space-y-3 px-1.5 pt-4">
                <div class="h-4 w-3/4 animate-pulse rounded bg-surface-sunken"></div>
                <div class="h-3 w-full animate-pulse rounded bg-surface-sunken"></div>
                <div class="h-3 w-1/2 animate-pulse rounded bg-surface-sunken"></div>
            </div>
        </div>
    @endforeach
</div>
