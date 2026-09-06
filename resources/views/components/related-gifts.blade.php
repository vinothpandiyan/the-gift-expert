@props([
    'products',
])

@if ($products->isNotEmpty())
    <section class="py-12 md:py-20">
        <x-ui.container>
            <div class="mb-7 md:mb-10">
                <p class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-ink-muted">Keep looking</p>
                <h2 class="font-serif text-[27px] leading-[1.15] tracking-tight text-ink md:text-4xl">You may also like</h2>
            </div>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-5 lg:grid-cols-4">
                @foreach ($products as $related)
                    <x-gift-card :product="$related" />
                @endforeach
            </div>
        </x-ui.container>
    </section>
@endif
