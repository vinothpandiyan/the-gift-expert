@extends('layouts.public')

@section('title', $product->meta_title ?: $product->name.' | '.config('app.name'))

@section('content')
    @php
        $images = $product->images;
        $primaryImage = $images->firstWhere('is_primary', true) ?? $images->first();
        $gallery = $images->reject(fn ($image) => $primaryImage && $image->is($primaryImage))->values();
        $affiliateLink = $product->affiliateLinks->firstWhere('is_primary', true) ?? $product->affiliateLinks->first();
        $primaryCategory = $product->categories->first(fn ($category) => (bool) $category->pivot->is_primary)
            ?? $product->categories->first();
    @endphp

    <article class="grid gap-8 lg:grid-cols-2">
        <div class="space-y-3">
            <div class="aspect-square overflow-hidden rounded-lg bg-stone-100">
                @if ($primaryImage)
                    <img
                        src="{{ $primaryImage->url() }}"
                        alt="{{ $primaryImage->alt_text ?: $product->name }}"
                        class="h-full w-full object-cover"
                    >
                @else
                    <div class="flex h-full items-center justify-center text-stone-400">No image</div>
                @endif
            </div>

            @if ($gallery->isNotEmpty())
                <div class="grid grid-cols-4 gap-2">
                    @foreach ($gallery as $image)
                        <div class="aspect-square overflow-hidden rounded-md bg-stone-100">
                            <img
                                src="{{ $image->url() }}"
                                alt="{{ $image->alt_text ?: $product->name }}"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            >
                        </div>
                    @endforeach
                </div>
            @endif
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

            @if ($product->description)
                <div class="prose prose-stone max-w-none whitespace-pre-line text-stone-700">
                    {{ $product->description }}
                </div>
            @endif

            @if ($affiliateLink)
                <div>
                    <a
                        href="{{ \App\Support\DiscoveryUrl::affiliateOut($affiliateLink->uuid) }}"
                        target="_blank"
                        rel="noopener noreferrer sponsored"
                        class="inline-flex items-center justify-center rounded-md bg-amber-600 px-5 py-3 text-sm font-semibold text-white hover:bg-amber-700"
                    >
                        View at {{ $affiliateLink->merchant?->name ?? 'merchant' }}
                    </a>
                </div>
            @endif


            <section class="space-y-3 border-t border-stone-200 pt-6 text-sm text-stone-600">
                @if ($primaryCategory)
                    <p>
                        <span class="font-medium text-stone-800">Category:</span>
                        <a href="{{ \App\Support\DiscoveryUrl::giftIdeasCategory($primaryCategory->full_path) }}" class="underline hover:text-stone-900">
                            {{ $primaryCategory->name }}
                        </a>
                    </p>
                @endif

                @if ($product->occasions->isNotEmpty())
                    <p>
                        <span class="font-medium text-stone-800">Occasions:</span>
                        {{ $product->occasions->pluck('name')->join(', ') }}
                    </p>
                @endif

                @if ($product->relationships->isNotEmpty())
                    <p>
                        <span class="font-medium text-stone-800">Relationships:</span>
                        {{ $product->relationships->pluck('name')->join(', ') }}
                    </p>
                @endif

                @if ($product->recipientTypes->isNotEmpty())
                    <p>
                        <span class="font-medium text-stone-800">Recipients:</span>
                        {{ $product->recipientTypes->pluck('name')->join(', ') }}
                    </p>
                @endif

                @if ($product->interests->isNotEmpty())
                    <p>
                        <span class="font-medium text-stone-800">Interests:</span>
                        {{ $product->interests->pluck('name')->join(', ') }}
                    </p>
                @endif

                @if ($product->professions->isNotEmpty())
                    <p>
                        <span class="font-medium text-stone-800">Professions:</span>
                        {{ $product->professions->pluck('name')->join(', ') }}
                    </p>
                @endif

                @if ($product->giftTypes->isNotEmpty())
                    <p>
                        <span class="font-medium text-stone-800">Gift types:</span>
                        {{ $product->giftTypes->pluck('name')->join(', ') }}
                    </p>
                @endif
            </section>
        </div>
    </article>
@endsection
