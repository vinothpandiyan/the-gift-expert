@extends('layouts.public')

@section('title', $seoTitle)

@section('content-uncontained')
    @php
        $merchantName = $detail->primaryMerchantName();
        $outboundUrl = $detail->outboundUrl();
        $badgeTone = $detail->badge === 'Personalized'
            ? 'border-plum/15 bg-plum-light text-plum'
            : 'border-gold/30 bg-gold/15 text-[#7a5a12]';
        $showDetailsBand = $detail->giftDetailRows !== [] || $detail->merchantOffers->isNotEmpty();
    @endphp

    <div @class(['bg-ivory', 'pb-24 md:pb-0' => $outboundUrl !== null])>
        <x-ui.container class="py-6">
            <x-breadcrumbs :items="$breadcrumbs" />
        </x-ui.container>

        <x-ui.container class="pb-12">
            <article class="grid gap-8 lg:grid-cols-[1.05fr_0.95fr] lg:gap-14 lg:items-start">
                <x-product-gallery :images="$detail->galleryImages" :product-name="$product->name" />

                <div>
                    @if ($detail->badge)
                        <span class="inline-flex items-center rounded-md border px-2 py-1 text-[11px] font-semibold tracking-wide {{ $badgeTone }}">
                            {{ $detail->badge }}
                        </span>
                    @endif

                    <h1 @class([
                        'font-serif text-[30px] leading-tight tracking-tight text-ink md:text-[42px]',
                        'mt-3' => filled($detail->badge),
                    ])>
                        {{ $product->name }}
                    </h1>

                    @if (filled($product->short_description))
                        <p class="mt-3 text-[16px] leading-relaxed text-ink-muted">{{ $product->short_description }}</p>
                    @endif

                    @if ($detail->priceLabel !== null || $merchantName !== null)
                        <div class="mt-6 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            @if ($detail->priceLabel !== null)
                                <span class="text-2xl font-semibold text-ink">{{ $detail->priceLabel }}</span>
                            @endif
                            @if ($merchantName !== null)
                                <span class="text-[14px] text-ink-muted">
                                    {{ $detail->priceLabel !== null ? 'at '.$merchantName : 'Available at '.$merchantName }}
                                </span>
                            @endif
                        </div>
                    @endif

                    <div class="mt-6">
                        @if ($outboundUrl !== null)
                            <x-ui.button
                                variant="primary"
                                :href="$outboundUrl"
                                target="_blank"
                                rel="noopener noreferrer nofollow sponsored"
                                class="h-14 w-full px-7 text-base sm:w-auto"
                            >
                                Check price at {{ $merchantName }}
                                <span class="sr-only">(opens in a new tab)</span>
                                <span aria-hidden="true">↗</span>
                            </x-ui.button>
                            <p class="mt-3 flex items-start gap-2 text-[13px] leading-relaxed text-ink-muted">
                                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" stroke-width="1.75" />
                                    <path stroke-linecap="round" stroke-width="1.75" d="M12 11v5" />
                                    <circle cx="12" cy="8" r="0.75" fill="currentColor" stroke="none" />
                                </svg>
                                Price and availability may change on the merchant website.
                            </p>
                        @else
                            <p class="text-[15px] leading-relaxed text-ink-muted">
                                We're currently checking where this gift is available.
                            </p>
                        @endif
                    </div>

                    @if ($detail->hasWhy())
                        <div class="mt-8 rounded-[14px] border border-line bg-surface p-5">
                            <h2 class="font-serif text-xl text-ink">Why it's a great gift</h2>
                            @if ($detail->whyAsList())
                                <ul class="mt-3 space-y-2.5">
                                    @foreach ($detail->whyItems as $item)
                                        <li class="flex gap-2.5 text-[15px] leading-relaxed text-ink">
                                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-plum" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <span>{{ $item }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="mt-3 text-[15px] leading-relaxed whitespace-pre-line text-ink">{{ $detail->whyItems[0] }}</p>
                            @endif
                        </div>
                    @endif

                    @if ($detail->bestForGroups !== [])
                        <div class="mt-6">
                            <h2 class="font-serif text-xl text-ink">Best for</h2>
                            <div class="mt-4 space-y-4">
                                @foreach ($detail->bestForGroups as $group)
                                    <div>
                                        <p class="mb-2 text-[12px] uppercase tracking-[0.12em] text-ink-muted">{{ $group['label'] }}</p>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($group['items'] as $chip)
                                                <x-taxonomy-chip :href="$chip['url']">{{ $chip['label'] }}</x-taxonomy-chip>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </article>
        </x-ui.container>

        @if ($showDetailsBand)
            <section class="bg-surface py-12 md:py-16">
                <x-ui.container>
                    <div class="grid gap-10 lg:grid-cols-2">
                        @if ($detail->giftDetailRows !== [])
                            <div>
                                <h2 class="font-serif text-2xl text-ink">Gift details</h2>
                                <x-gift-details-rows :rows="$detail->giftDetailRows" />
                            </div>
                        @endif

                        @if ($detail->merchantOffers->isNotEmpty())
                            <div>
                                <h2 class="font-serif text-2xl text-ink">Where to buy</h2>
                                <p class="mt-2 text-[14px] text-ink-muted">
                                    Listings we've found for this gift. Prices are indicative and set by the merchant.
                                </p>
                                <ul class="mt-4 space-y-3">
                                    @foreach ($detail->merchantOffers as $offer)
                                        <x-merchant-offer :affiliate-link="$offer" />
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </x-ui.container>
            </section>
        @endif

        <x-related-gifts :products="$relatedProducts" />
    </div>

    @if ($outboundUrl !== null)
        <div class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:hidden">
            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
                <div class="min-w-0">
                    @if ($detail->priceLabel !== null)
                        <p class="truncate text-[13px] text-ink-muted">{{ $detail->priceLabel }}</p>
                    @endif
                    @if ($merchantName !== null)
                        <p class="truncate text-[13px] font-semibold text-ink">at {{ $merchantName }}</p>
                    @endif
                </div>
                <x-ui.button
                    variant="primary"
                    :href="$outboundUrl"
                    target="_blank"
                    rel="noopener noreferrer nofollow sponsored"
                    class="shrink-0 px-5"
                >
                    Check price
                    <span class="sr-only">at {{ $merchantName }} (opens in a new tab)</span>
                    <span aria-hidden="true">↗</span>
                </x-ui.button>
            </div>
        </div>
    @endif
@endsection
