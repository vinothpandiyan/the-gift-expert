<x-home.section>
    <div class="grid gap-8 border-t border-line pt-10 md:grid-cols-4">
        <div>
            <h3 class="text-[15px] font-semibold">Curated gift ideas</h3>
            <p class="mt-2 text-[14px] leading-relaxed text-ink-muted">Thoughtful recommendations, not an endless catalogue dump.</p>
        </div>
        <div>
            <h3 class="text-[15px] font-semibold">Multiple merchants</h3>
            <p class="mt-2 text-[14px] leading-relaxed text-ink-muted">We point you to merchants where the gift is actually available.</p>
        </div>
        <div>
            <h3 class="text-[15px] font-semibold">Every budget</h3>
            <p class="mt-2 text-[14px] leading-relaxed text-ink-muted">From small tokens to milestone presents — browse by what you want to spend.</p>
        </div>
        <div>
            <h3 class="text-[15px] font-semibold">Easy gift discovery</h3>
            <p class="mt-2 text-[14px] leading-relaxed text-ink-muted">Start with a person, an occasion, or a few quick Finder questions.</p>
        </div>
    </div>
    <p class="mt-10 text-center text-[15px] text-ink-muted">
        Still stuck?
        <a href="{{ \App\Support\DiscoveryUrl::finder() }}" class="font-semibold text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
            Try the Gift Finder
        </a>
    </p>
</x-home.section>
