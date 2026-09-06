@props([
    'product',
    'context' => null,
])

@php
    $primaryImage = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
    $affiliateLink = $product->affiliateLinks->firstWhere('is_primary', true) ?? $product->affiliateLinks->first();
    $merchantName = $affiliateLink?->merchant?->name;
    $giftUrl = \App\Support\DiscoveryUrl::gift(
        $product->slug,
        context: is_string($context) && $context !== '' ? $context : null,
    );
    $price = \App\Support\Money::around($product->price_amount, $product->price_currency);
    $isPersonalized = $product->relationLoaded('categories')
        && $product->categories->contains(fn ($category) => $category->slug === 'personalized-gifts');
    $badge = $product->is_featured ? 'Featured' : ($isPersonalized ? 'Personalized' : null);
@endphp

<article {{ $attributes->merge(['class' => 'flex flex-col overflow-hidden rounded-lg border border-line bg-surface']) }}>
    <a href="{{ $giftUrl }}" class="relative block aspect-square bg-plum-light p-4">
        @if ($badge)
            <span class="absolute left-3 top-3 rounded-full bg-surface px-2.5 py-1 text-xs font-medium text-plum">
                {{ $badge }}
            </span>
        @endif

        @if ($primaryImage)
            <img
                src="{{ $primaryImage->url() }}"
                alt="{{ $primaryImage->alt_text ?: $product->name }}"
                class="h-full w-full object-contain"
                loading="lazy"
            >
        @else
            <x-gift-image-placeholder class="h-full" />
        @endif
    </a>

    <div class="flex flex-1 flex-col gap-2 p-4">
        <h3 class="text-base font-semibold leading-snug text-ink">
            <a href="{{ $giftUrl }}" class="hover:text-plum hover:underline">
                {{ $product->name }}
            </a>
        </h3>

        @if (filled($product->short_description))
            <p class="line-clamp-2 text-sm leading-relaxed text-ink-muted">
                {{ $product->short_description }}
            </p>
        @endif

        @if ($price !== null)
            <p class="text-sm font-medium text-ink">
                {{ $price }}
            </p>
        @endif

        @if ($merchantName)
            <p class="text-xs text-ink-muted">
                At {{ $merchantName }}
            </p>
        @endif

        <a
            href="{{ $giftUrl }}"
            class="mt-auto inline-flex min-h-11 items-center gap-1 text-sm font-medium text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
        >
            View gift
            <span aria-hidden="true">↗</span>
        </a>
    </div>
</article>
