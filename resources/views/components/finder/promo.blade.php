@props([
    'finderUrl' => null,
])

@php
    $finderUrl = $finderUrl ?? \App\Support\DiscoveryUrl::finder();
@endphp

<section {{ $attributes->merge(['class' => 'flex flex-col gap-5 rounded-xl bg-plum px-6 py-8 text-white md:flex-row md:items-center md:justify-between md:px-10 md:py-10']) }}>
    <div class="max-w-xl">
        <p class="mb-2 inline-flex items-center gap-2 text-[12px] font-semibold uppercase tracking-[0.12em] text-white/80">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-3.5" aria-hidden="true">
                <path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
            </svg>
            Gift Finder
        </p>
        <h2 class="font-serif text-2xl md:text-3xl">Still not sure what they'd like?</h2>
        <p class="mt-2 text-[15px] text-white/85">Answer a few questions and we'll narrow it down to a handful of ideas.</p>
    </div>
    <x-ui.button :href="$finderUrl" variant="coral" class="h-14 w-full shrink-0 px-7 text-base md:w-auto">
        Start Gift Finder
    </x-ui.button>
</section>
