@props([
    'count' => 6,
])

<div {{ $attributes->merge(['class' => 'grid gap-4 sm:grid-cols-2 xl:grid-cols-3']) }} aria-hidden="true">
    @foreach (range(1, $count) as $item)
        <div class="overflow-hidden rounded-lg border border-line bg-surface">
            <div class="aspect-square animate-pulse bg-plum-light"></div>
            <div class="space-y-3 p-4">
                <div class="h-4 w-3/4 animate-pulse rounded bg-plum-light"></div>
                <div class="h-3 w-full animate-pulse rounded bg-plum-light"></div>
                <div class="h-3 w-1/2 animate-pulse rounded bg-plum-light"></div>
            </div>
        </div>
    @endforeach
</div>
