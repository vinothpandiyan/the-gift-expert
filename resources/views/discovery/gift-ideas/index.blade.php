@extends('layouts.public')

@section('title', $seoTitle)

@section('page-header')
    <x-listing.page-header :breadcrumbs="$breadcrumbs" :heading="\App\Support\Terminology::giftIdeas()">
        <p class="mt-3 max-w-2xl text-[15px] leading-relaxed text-ink-muted">
            @if (($mode ?? 'hub') === 'listing' && isset($budgetRange))
                Gift ideas in the {{ $budgetRange->name }} range. Narrow it down by who it's for, the occasion, or what they're into.
            @else
                Choose a route into discovery — by recipient, occasion, interest, gift type, budget, or category.
            @endif
        </p>
    </x-listing.page-header>
@endsection

@section('content')
    @if (($mode ?? 'hub') === 'listing')
        <livewire:gift-listing :context="$listingContext" />
    @else
        @if ($relationships->isNotEmpty())
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">By recipient</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($relationships as $item)
                        <x-discovery.tile :href="\App\Support\DiscoveryUrl::relationship($item->slug)">
                            <span>{{ $item->name }}</span>
                            <span class="text-plum" aria-hidden="true">→</span>
                        </x-discovery.tile>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($occasions->isNotEmpty())
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">By occasion</h2>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    @foreach ($occasions as $item)
                        <x-discovery.tile variant="occasion" :href="\App\Support\DiscoveryUrl::occasion($item->slug)" class="min-h-[120px]">
                            <span class="text-plum"><x-discovery.icon :name="$item->slug" /></span>
                            <span class="font-serif text-lg">{{ $item->name }}</span>
                        </x-discovery.tile>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($interests->isNotEmpty())
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">By interest</h2>
                <ul class="flex flex-wrap gap-2.5">
                    @foreach ($interests as $item)
                        <li>
                            <x-discovery.chip :href="\App\Support\DiscoveryUrl::interest($item->slug)">
                                <span class="text-plum"><x-discovery.icon :name="$item->slug" size="sm" /></span>
                                {{ $item->name }}
                            </x-discovery.chip>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($giftTypes->isNotEmpty())
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">By gift type</h2>
                <ul class="flex flex-wrap gap-2.5">
                    @foreach ($giftTypes as $item)
                        <li>
                            <x-discovery.chip :href="\App\Support\DiscoveryUrl::giftType($item->slug)">
                                {{ $item->name }}
                            </x-discovery.chip>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($budgetRanges->isNotEmpty())
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">By budget</h2>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                    @foreach ($budgetRanges as $item)
                        <x-discovery.tile variant="budget" :href="\App\Support\DiscoveryUrl::giftIdeasQuery(['budget' => $item->slug])">
                            <span class="text-[15px] font-semibold">{{ $item->name }}</span>
                        </x-discovery.tile>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($recipientTypes->isNotEmpty())
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">By recipient type</h2>
                <ul class="flex flex-wrap gap-2.5">
                    @foreach ($recipientTypes as $item)
                        <li>
                            <x-discovery.chip :href="\App\Support\DiscoveryUrl::recipientType($item->slug)">
                                {{ $item->name }}
                            </x-discovery.chip>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($professions->isNotEmpty())
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">By profession</h2>
                <ul class="flex flex-wrap gap-2.5">
                    @foreach ($professions as $item)
                        <li>
                            <x-discovery.chip :href="\App\Support\DiscoveryUrl::profession($item->slug)">
                                {{ $item->name }}
                            </x-discovery.chip>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($categories->isNotEmpty())
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">Categories</h2>
                <ul class="flex flex-wrap gap-2.5">
                    @foreach ($categories as $item)
                        <li>
                            <x-discovery.chip :href="\App\Support\DiscoveryUrl::giftIdeasCategory($item->full_path)">
                                {{ $item->name }}
                            </x-discovery.chip>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($featuredGifts->isNotEmpty())
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">Popular gift ideas</h2>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-5 xl:grid-cols-4">
                    @foreach ($featuredGifts as $product)
                        <x-gift-card :product="$product" />
                    @endforeach
                </div>
            </section>
        @endif

        <x-finder.promo class="mt-4" />
    @endif
@endsection
