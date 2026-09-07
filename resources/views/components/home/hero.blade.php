@props([
    'relationships',
    'occasions',
    'budgetRanges',
])

<section class="border-b border-line bg-ivory">
    <x-ui.container class="py-10 md:py-16">
        <div class="grid items-center gap-10 lg:grid-cols-[1.05fr_0.95fr]">
            <div>
                <p class="inline-flex items-center gap-1.5 rounded-full border border-plum/15 bg-plum-light px-3 py-1 text-[12px] font-semibold tracking-wide text-plum">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-3.5" aria-hidden="true">
                        <path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                    </svg>
                    Gift discovery, not a catalogue
                </p>
                <h1 class="mt-4 font-serif text-[38px] leading-[1.08] tracking-tight text-ink md:text-[56px]">
                    Find a gift they'll actually love.
                </h1>
                <p class="mt-4 max-w-lg text-[16px] leading-relaxed text-ink-muted md:text-[18px]">
                    Thoughtful gift ideas for every person, occasion and budget — with a reason why each one works.
                </p>
                <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <x-ui.button :href="\App\Support\DiscoveryUrl::finder()" class="h-14 w-full px-7 text-base sm:w-auto">
                        Find a Gift
                    </x-ui.button>
                    <x-ui.button :href="\App\Support\DiscoveryUrl::giftIdeas()" variant="secondary" class="h-14 w-full px-7 text-base sm:w-auto">
                        Browse Gift Ideas
                    </x-ui.button>
                </div>
                <div class="mt-8">
                    <x-home.discovery-selector
                        :relationships="$relationships"
                        :occasions="$occasions"
                        :budget-ranges="$budgetRanges"
                    />
                </div>
            </div>

            <div class="order-first lg:order-none">
                <div class="overflow-hidden rounded-2xl border border-line">
                    <img
                        src="{{ asset('images/home/hero-gifting.jpg') }}"
                        alt="A wrapped gift being handed from one person to another"
                        width="1200"
                        height="1408"
                        fetchpriority="high"
                        class="h-56 w-full object-cover sm:h-72 lg:h-[520px]"
                    >
                </div>
            </div>
        </div>
    </x-ui.container>
</section>
