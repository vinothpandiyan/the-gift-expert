<div>
    @php
        use App\Support\Terminology;
    @endphp

    <header class="mb-8 space-y-3">
        <p class="text-sm font-medium uppercase tracking-wide text-stone-500">
            {{ Terminology::giftRecommendations() }}
        </p>
        <h1 class="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
            Your {{ Terminology::gift() }} picks
        </h1>
        <p class="text-base text-stone-600">
            @if ($resultCount === 0)
                We could not find matching {{ strtolower(Terminology::gifts()) }} for those details.
            @elseif ($resultCount === 1)
                1 {{ strtolower(Terminology::gift()) }} matched your details.
            @else
                {{ $resultCount }} {{ strtolower(Terminology::gifts()) }} matched your details.
            @endif
        </p>
    </header>

    @if ($results->isEmpty())
        <div class="space-y-6 rounded-lg border border-dashed border-stone-300 bg-white px-6 py-10 text-center">
            <p class="text-stone-600">
                Try adjusting your filters or start over with fewer details.
            </p>
            <a
                href="{{ $finderUrl }}"
                class="inline-flex items-center justify-center rounded-md bg-stone-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-stone-800"
            >
                Start Over
            </a>
        </div>
    @else
        <div class="mb-6">
            <a href="{{ $finderUrl }}" class="text-sm font-medium text-stone-700 underline hover:text-stone-900">
                Start Over
            </a>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($results as $result)
                <x-gift-card :product="$result->product" />
            @endforeach
        </div>
    @endif
</div>
