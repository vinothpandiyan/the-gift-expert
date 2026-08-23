@extends('layouts.public')

@section('title', $seoTitle)

@section('content')
    @php
        $images = $product->images;
        $primaryImage = $images->firstWhere('is_primary', true) ?? $images->first();
        $affiliateLink = $product->affiliateLinks->firstWhere('is_primary', true) ?? $product->affiliateLinks->first();
        $merchantName = $affiliateLink?->merchant?->name ?? 'merchant';
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <article class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] lg:items-start">
        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white">
            <div class="aspect-square bg-stone-50">
                @if ($primaryImage)
                    <img
                        src="{{ $primaryImage->url() }}"
                        alt="{{ $primaryImage->alt_text ?: $product->name }}"
                        class="h-full w-full object-cover"
                    >
                @else
                    <x-gift-image-placeholder />
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <header class="space-y-3">
                <p class="text-sm font-medium uppercase tracking-wide text-stone-500">
                    {{ \App\Support\Terminology::gift() }}
                </p>
                <h1 class="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
                    {{ $product->name }}
                </h1>

                @if ($product->brand)
                    <p class="text-sm text-stone-600">{{ $product->brand }}</p>
                @endif

                @if ($product->price_amount !== null)
                    <p class="text-2xl font-semibold text-stone-900">
                        {{ $product->price_currency }} {{ number_format((float) $product->price_amount, 2) }}
                        @if ($product->compare_at_amount !== null)
                            <span class="ml-2 text-base font-normal text-stone-400 line-through">
                                {{ number_format((float) $product->compare_at_amount, 2) }}
                            </span>
                        @endif
                    </p>
                @endif
            </header>

            @if ($product->short_description)
                <p class="text-base leading-relaxed text-stone-700">{{ $product->short_description }}</p>
            @endif

            @if ($affiliateLink)
                <div class="space-y-2">
                    <a
                        href="{{ \App\Support\DiscoveryUrl::affiliateOut($affiliateLink->uuid) }}"
                        target="_blank"
                        rel="noopener noreferrer sponsored"
                        class="inline-flex items-center justify-center rounded-md bg-amber-600 px-5 py-3 text-sm font-semibold text-white hover:bg-amber-700"
                    >
                        View on {{ $merchantName }}
                    </a>
                    <p class="text-xs leading-relaxed text-stone-500">
                        As an affiliate, we may earn from qualifying purchases. Price and availability are confirmed on {{ $merchantName }}.
                    </p>
                </div>
            @endif

            @if ($product->relationships->isNotEmpty() || $product->occasions->isNotEmpty() || $product->interests->isNotEmpty())
                <section class="space-y-4 rounded-lg border border-stone-200 bg-white p-5">
                    @if ($product->relationships->isNotEmpty())
                        <div class="space-y-2">
                            <h2 class="text-sm font-semibold text-stone-900">Best for</h2>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($product->relationships as $relationship)
                                    <a
                                        href="{{ \App\Support\DiscoveryUrl::relationship($relationship->slug) }}"
                                        class="inline-flex rounded-full bg-stone-100 px-3 py-1 text-sm text-stone-700 hover:bg-stone-200"
                                    >
                                        {{ $relationship->name }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($product->occasions->isNotEmpty())
                        <div class="space-y-2">
                            <h2 class="text-sm font-semibold text-stone-900">Good for</h2>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($product->occasions as $occasion)
                                    <a
                                        href="{{ \App\Support\DiscoveryUrl::occasion($occasion->slug) }}"
                                        class="inline-flex rounded-full bg-amber-50 px-3 py-1 text-sm text-amber-900 hover:bg-amber-100"
                                    >
                                        {{ $occasion->name }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($product->interests->isNotEmpty())
                        <div class="space-y-2">
                            <h2 class="text-sm font-semibold text-stone-900">Interests</h2>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($product->interests as $interest)
                                    <a
                                        href="{{ \App\Support\DiscoveryUrl::interest($interest->slug) }}"
                                        class="inline-flex rounded-full bg-stone-100 px-3 py-1 text-sm text-stone-700 hover:bg-stone-200"
                                    >
                                        {{ $interest->name }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>
            @endif

            @if ($product->description)
                <section class="space-y-2">
                    <h2 class="text-lg font-semibold text-stone-900">Why we picked it</h2>
                    <div class="prose prose-stone max-w-none whitespace-pre-line text-base leading-relaxed text-stone-700">
                        {{ $product->description }}
                    </div>
                </section>
            @endif
        </div>
    </article>
@endsection
