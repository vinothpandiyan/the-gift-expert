@extends('layouts.public')

@section('title', $seoTitle)

@section('content-uncontained')
    <x-home.hero
        :relationships="$relationships"
        :occasions="$occasions"
        :budget-ranges="$budgetRanges"
    />

    @if ($relationships->isNotEmpty())
        <x-home.section tone="surface">
            <x-home.section-heading
                eyebrow="Start here"
                heading="Who are you shopping for?"
                description="Pick a person and we'll show ideas chosen for that relationship."
            />
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($relationships as $relationship)
                    <x-discovery.tile :href="\App\Support\DiscoveryUrl::relationship($relationship->slug)">
                        <span>{{ $relationship->name }}</span>
                        <span class="text-plum" aria-hidden="true">→</span>
                    </x-discovery.tile>
                @endforeach
            </div>
        </x-home.section>
    @endif

    @if ($occasions->isNotEmpty())
        <x-home.section>
            <x-home.section-heading eyebrow="By occasion" heading="What's the occasion?">
                <a href="{{ \App\Support\DiscoveryUrl::giftIdeas() }}" class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                    All occasions <span aria-hidden="true">→</span>
                </a>
            </x-home.section-heading>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 md:gap-5">
                @foreach ($occasions as $occasion)
                    <x-discovery.tile variant="occasion" :href="\App\Support\DiscoveryUrl::occasion($occasion->slug)">
                        <span class="text-plum"><x-discovery.icon :name="$occasion->slug" /></span>
                        <span class="font-serif text-lg group-hover:text-plum">{{ $occasion->name }}</span>
                    </x-discovery.tile>
                @endforeach
            </div>
        </x-home.section>
    @endif

    @if ($featuredGifts->isNotEmpty())
        <x-home.section tone="surface">
            <x-home.section-heading
                eyebrow="Curated this week"
                heading="Trending gift ideas"
                description="Editorially featured gifts with an active merchant listing."
            />
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-5 lg:grid-cols-4">
                @foreach ($featuredGifts as $product)
                    <x-gift-card :product="$product" />
                @endforeach
            </div>
        </x-home.section>
    @endif

    <x-home.finder-promo />

    @if ($interests->isNotEmpty())
        <x-home.section>
            <x-home.section-heading eyebrow="By interest" heading="What are they into?" />
            <div class="flex flex-wrap gap-2.5">
                @foreach ($interests as $interest)
                    <x-discovery.chip :href="\App\Support\DiscoveryUrl::interest($interest->slug)" class="text-[15px]">
                        <span class="text-plum"><x-discovery.icon :name="$interest->slug" size="sm" /></span>
                        {{ $interest->name }}
                    </x-discovery.chip>
                @endforeach
            </div>
        </x-home.section>
    @endif

    @if ($budgetRanges->isNotEmpty())
        <x-home.section tone="surface">
            <x-home.section-heading eyebrow="By budget" heading="How much are you spending?" />
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                @foreach ($budgetRanges as $budget)
                    <x-discovery.tile variant="budget" :href="\App\Support\DiscoveryUrl::giftIdeasQuery(['budget' => $budget->slug])">
                        <span class="text-[15px] font-semibold">{{ $budget->name }}</span>
                    </x-discovery.tile>
                @endforeach
            </div>
        </x-home.section>
    @endif

    @if ($returnGifts)
        <x-home.section>
            <div class="flex flex-col gap-6 rounded-xl border border-line bg-plum-light/60 p-6 md:flex-row md:items-center md:justify-between md:p-8">
                <div class="max-w-xl">
                    <h2 class="font-serif text-2xl md:text-[28px]">Return gift ideas</h2>
                    <p class="mt-2 text-[15px] leading-relaxed text-ink-muted">
                        Hosting a birthday, wedding or house celebration? Browse return gift ideas that are useful, easy to buy in bulk and kind to your budget.
                    </p>
                </div>
                <x-ui.button :href="\App\Support\DiscoveryUrl::giftType($returnGifts->slug)" variant="secondary" class="h-14 shrink-0 px-7 text-base">
                    Browse return gifts
                </x-ui.button>
            </div>
        </x-home.section>
    @endif

    @if ($inspirationPages->isNotEmpty())
        <x-home.section tone="surface">
            <x-home.section-heading eyebrow="Inspiration" heading="Gifting guides worth reading" />
            <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-4">
                @foreach ($inspirationPages as $page)
                    <a
                        href="{{ \App\Support\DiscoveryUrl::seoLandingPage($page->slug) }}"
                        class="group block border-t-2 border-plum/15 pt-4 transition-colors hover:border-plum focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                    >
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-gold">Guide</p>
                        <h3 class="mt-2 font-serif text-xl leading-snug group-hover:text-plum">{{ $page->heading }}</h3>
                        @if (filled($page->intro_content))
                            <p class="mt-2 text-[14px] leading-relaxed text-ink-muted">{{ \Illuminate\Support\Str::limit(trim(strip_tags($page->intro_content)), 140) }}</p>
                        @endif
                    </a>
                @endforeach
            </div>
        </x-home.section>
    @endif

    <x-home.trust-strip />
@endsection
