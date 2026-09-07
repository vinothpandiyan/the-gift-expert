@props([
    'editUrl',
    'startOverUrl',
    'giftIdeasUrl',
])

<div class="rounded-xl border border-dashed border-line bg-surface px-6 py-14 text-center">
    <h2 class="font-serif text-2xl text-ink">We couldn't find a strong match yet.</h2>
    <p class="mx-auto mt-2 max-w-md text-[15px] text-ink-muted">
        Try broadening your search — change who it's for, the occasion, or the budget.
    </p>
    <div class="mt-6 flex flex-wrap justify-center gap-3">
        <x-ui.button :href="$editUrl" variant="primary">
            Edit preferences
        </x-ui.button>
        <x-ui.button :href="$startOverUrl" variant="secondary">
            Start over
        </x-ui.button>
        <x-ui.button :href="$giftIdeasUrl" variant="ghost">
            Browse gift ideas
        </x-ui.button>
    </div>
</div>
