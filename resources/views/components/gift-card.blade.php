@props([
    'product',
    'context' => null,
    'matchReason' => null,
    'greatMatch' => false,
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
    $isPersonalized = $product->isPersonalized();
    $badge = $greatMatch
        ? 'Great Match'
        : ($product->is_featured ? 'Featured' : ($isPersonalized ? 'Personalized' : null));
    $badgeTone = $badge === 'Personalized'
        ? 'border-plum/15 bg-plum-light text-plum'
        : 'border-gold/30 bg-gold/15 text-gold-ink';
    $reason = filled($matchReason) ? $matchReason : $product->short_description;
    $imageWidth = (int) config('media.product_images.canonical_width');
    $imageHeight = (int) config('media.product_images.canonical_height');
@endphp

<article {{ $attributes->merge(['class' => 'group relative flex h-full flex-col rounded-xl border border-line bg-surface p-3 transition-shadow hover:shadow-[0_8px_24px_-16px_rgba(38,35,38,0.35)]']) }}>
    <div class="relative aspect-square overflow-hidden rounded-md bg-surface-sunken p-1.5">
        @if ($primaryImage)
            <img
                src="{{ $primaryImage->url() }}"
                alt="{{ $primaryImage->alt_text ?: $product->name }}"
                class="h-full w-full object-contain motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-[1.02]"
                width="{{ $imageWidth }}"
                height="{{ $imageHeight }}"
                loading="lazy"
            >
        @else
            <x-gift-image-placeholder class="h-full bg-transparent" />
        @endif
    </div>

    <div class="flex flex-1 flex-col px-1.5 pt-4 pb-1">
        @if ($badge)
            <div class="mb-2">
                <span class="inline-flex items-center rounded-sm border px-2 py-1 text-[11px] font-semibold tracking-wide {{ $badgeTone }}">
                    {{ $badge }}
                </span>
            </div>
        @endif

        <h3 class="line-clamp-2 text-[15px] font-semibold leading-snug text-ink">
            <a href="{{ $giftUrl }}" class="hover:text-plum after:absolute after:inset-0">
                {{ $product->name }}
            </a>
        </h3>

        @if (filled($reason))
            <p class="mt-1.5 line-clamp-2 text-[13px] leading-relaxed text-ink-muted">
                {{ $reason }}
            </p>
        @endif

        <div class="mt-auto flex flex-wrap items-center justify-between gap-x-3 gap-y-1 pt-4">
            @if ($price !== null)
                <span class="text-sm font-semibold text-ink">{{ $price }}</span>
            @else
                <span></span>
            @endif

            <span class="inline-flex items-center gap-1 text-[13px] font-medium text-plum">
                View gift
                <span aria-hidden="true">↗</span>
            </span>
        </div>

        @if ($merchantName)
            <p class="mt-1 text-[11px] text-ink-muted">
                Available at {{ $merchantName }}
            </p>
        @endif
    </div>
</article>
