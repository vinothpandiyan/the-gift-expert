@props([
    'product',
])

@php
    $primaryImage = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
    $affiliateLink = $product->affiliateLinks->firstWhere('is_primary', true) ?? $product->affiliateLinks->first();
    $merchantName = $affiliateLink?->merchant?->name;
@endphp

<article {{ $attributes->merge(['class' => 'flex flex-col overflow-hidden rounded-lg border border-stone-200 bg-white']) }}>
    <a href="{{ \App\Support\DiscoveryUrl::gift($product->slug) }}" class="block aspect-[4/3] bg-stone-100">
        @if ($primaryImage)
            <img
                src="{{ $primaryImage->url() }}"
                alt="{{ $primaryImage->alt_text ?: $product->name }}"
                class="h-full w-full object-cover"
                loading="lazy"
            >
        @else
            <div class="flex h-full items-center justify-center text-sm text-stone-400">
                No image
            </div>
        @endif
    </a>

    <div class="flex flex-1 flex-col gap-2 p-4">
        <h3 class="text-base font-semibold leading-snug text-stone-900">
            <a href="{{ \App\Support\DiscoveryUrl::gift($product->slug) }}" class="hover:underline">
                {{ $product->name }}
            </a>
        </h3>

        @if ($product->price_amount !== null)
            <p class="text-sm font-medium text-stone-800">
                {{ $product->price_currency }} {{ number_format((float) $product->price_amount, 2) }}
                @if ($product->compare_at_amount !== null)
                    <span class="ml-1 text-stone-400 line-through">
                        {{ number_format((float) $product->compare_at_amount, 2) }}
                    </span>
                @endif
            </p>
        @endif

        @if ($affiliateLink)
            <a
                href="{{ \App\Support\DiscoveryUrl::affiliateOut($affiliateLink->uuid) }}"
                target="_blank"
                rel="noopener noreferrer sponsored"
                class="mt-auto text-xs font-medium text-amber-700 hover:underline"
            >
                View at {{ $merchantName ?? 'merchant' }}
            </a>
        @endif
    </div>
</article>
