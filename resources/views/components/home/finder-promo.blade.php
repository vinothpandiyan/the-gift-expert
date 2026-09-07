<x-home.section tone="plum">
    <div class="grid items-center gap-10 lg:grid-cols-2">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-white/80">Gift Finder</p>
            <h2 class="mt-3 font-serif text-[30px] leading-tight md:text-[42px]">Not sure what to get them?</h2>
            <p class="mt-4 max-w-md text-[16px] leading-relaxed text-white/90">
                Answer a few quick questions and we'll narrow down the best gift ideas — with a reason why each one suits them.
            </p>
            <x-ui.button :href="\App\Support\DiscoveryUrl::finder()" variant="coral" class="mt-7 h-14 px-7 text-base">
                Start Gift Finder
            </x-ui.button>
        </div>

        <ol class="grid gap-3 rounded-xl border border-white/20 bg-plum-dark/40 p-5" aria-hidden="true">
            @foreach ([
                ['Recipient', 'Who you are buying for'],
                ['Occasion', 'What you are celebrating'],
                ['Interests', 'What they are into'],
                ['Budget', 'How much you would like to spend'],
            ] as $index => [$step, $detail])
                <li class="flex items-center gap-4 rounded-md bg-plum/40 px-4 py-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-plum-light text-sm font-semibold text-plum">
                        {{ $index + 1 }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[12px] uppercase tracking-wide text-white/70">Step {{ $index + 1 }} · {{ $step }}</span>
                        <span class="block truncate text-[15px] font-semibold">{{ $detail }}</span>
                    </span>
                </li>
            @endforeach
            <li class="mt-1 rounded-md border border-gold/40 px-4 py-3 text-[15px] font-semibold text-gold">
                Ranked gift ideas, with a reason for each one
            </li>
        </ol>
    </div>
</x-home.section>
