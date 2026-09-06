<div>
    <div class="rounded-[14px] border border-line bg-surface p-5 md:p-6">
        <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <span class="inline-flex items-center gap-1.5 text-[12px] font-semibold tracking-wide text-plum uppercase">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5" aria-hidden="true">
                        <path d="M12 2.5 13.2 8l5.8.4-4.4 3.6 1.4 5.5L12 14.8 7.99 17.5 9.4 12 5 8.4 10.8 8 12 2.5Z" />
                    </svg>
                    Gift Finder
                </span>
                <h1 class="mt-2 font-serif text-[28px] leading-tight tracking-tight text-ink md:text-[38px]">
                    {{ $heading }}
                </h1>
            </div>
            <x-ui.button :href="$editUrl" variant="secondary" class="shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3.5 w-3.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.651 1.651a1.875 1.875 0 010 2.652L9.75 17.553 5.25 18.75l1.197-4.5 8.763-8.763a1.875 1.875 0 012.652 0z" />
                </svg>
                Edit preferences
            </x-ui.button>
        </div>

        <x-finder.summary :items="$summaryItems" />
    </div>

    @if ($results->isEmpty())
        <div class="mt-8">
            <x-finder.empty-state
                :edit-url="$editUrl"
                :start-over-url="$finderUrl"
                :gift-ideas-url="$giftIdeasUrl"
            />
        </div>
    @else
        <div class="mt-10">
            <div class="mb-6 flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-[12px] font-semibold tracking-[0.12em] text-ink-muted uppercase">Ranked for this person</p>
                    <h2 class="mt-1 font-serif text-2xl text-ink md:text-[28px]">
                        {{ $resultCount }} {{ $resultCount === 1 ? 'idea' : 'ideas' }} worth giving
                    </h2>
                    <p class="mt-1 text-[15px] text-ink-muted">
                        Ordered by how well they fit — not by price or commission.
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-5 lg:grid-cols-4">
                @foreach ($results as $index => $result)
                    <x-gift-card
                        :product="$result->product"
                        :match-reason="$result->explanation"
                        :great-match="$this->isGreatMatch($result, $index + 1)"
                    />
                @endforeach
            </div>

            <div class="mt-10 flex flex-col items-center gap-3 rounded-[14px] border border-line bg-surface p-6 text-center">
                <span class="inline-flex items-center rounded-md border border-plum/15 bg-plum-light px-2 py-1 text-[11px] font-semibold tracking-wide text-plum">
                    Still deciding?
                </span>
                <p class="max-w-md text-[15px] text-ink-muted">
                    Change one answer — the budget or an interest — and we'll rerank everything around it.
                </p>
                <x-ui.button :href="$editUrl" variant="secondary">
                    Adjust my answers
                </x-ui.button>
            </div>
        </div>
    @endif
</div>
