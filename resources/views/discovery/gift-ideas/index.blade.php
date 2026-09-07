@extends('layouts.public')

@section('title', $seoTitle)

@section('page-header')
    <x-listing.page-header :breadcrumbs="$breadcrumbs" :heading="\App\Support\Terminology::giftIdeas()">
        <p class="mt-3 max-w-2xl text-[15px] leading-relaxed text-ink-muted">
            @if (($mode ?? 'hub') === 'listing' && isset($budgetRange))
                Gift ideas in the {{ $budgetRange->name }} range. Narrow it down by who it's for, the occasion, or what they're into.
            @else
                Start with who it's for, the occasion, or a gift type — then use Gift Finder if you want a shorter shortlist.
            @endif
        </p>
    </x-listing.page-header>
@endsection

@section('content')
    @if (($mode ?? 'hub') === 'listing')
        <livewire:gift-listing :context="$listingContext" />
    @else
        @if ($recipients !== [])
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">Shop by recipient</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($recipients as $item)
                        <x-discovery.tile :href="$item['href']">
                            <span>{{ $item['name'] }}</span>
                            <span class="text-plum" aria-hidden="true">→</span>
                        </x-discovery.tile>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($occasions !== [])
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">Shop by occasion</h2>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    @foreach ($occasions as $item)
                        <x-discovery.tile variant="occasion" :href="$item['href']" class="min-h-[120px]">
                            <span class="text-plum"><x-discovery.icon :name="$item['slug']" /></span>
                            <span class="font-serif text-lg">{{ $item['name'] }}</span>
                        </x-discovery.tile>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($interests !== [])
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">Shop by interest</h2>
                <ul class="flex flex-wrap gap-2.5">
                    @foreach ($interests as $item)
                        <li>
                            <x-discovery.chip :href="$item['href']">
                                <span class="text-plum"><x-discovery.icon :name="$item['slug']" size="sm" /></span>
                                {{ $item['name'] }}
                            </x-discovery.chip>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($giftTypes !== [])
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">Shop by gift type</h2>
                <ul class="flex flex-wrap gap-2.5">
                    @foreach ($giftTypes as $item)
                        <li>
                            <x-discovery.chip :href="$item['href']">
                                {{ $item['name'] }}
                            </x-discovery.chip>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($budgetRanges->isNotEmpty())
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">Shop by budget</h2>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                    @foreach ($budgetRanges as $item)
                        <x-discovery.tile variant="budget" :href="\App\Support\DiscoveryUrl::giftIdeasQuery(['budget' => $item->slug])">
                            <span class="text-[15px] font-semibold">{{ $item->name }}</span>
                        </x-discovery.tile>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($categories !== [])
            <section class="mb-12">
                <h2 class="mb-4 font-serif text-xl text-plum">Shop by category</h2>
                <ul class="flex flex-wrap gap-2.5">
                    @foreach ($categories as $item)
                        <li>
                            <x-discovery.chip :href="$item['href']">
                                {{ $item['name'] }}
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
