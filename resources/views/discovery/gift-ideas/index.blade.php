@extends('layouts.public')

@section('title', $seoTitle)

@section('content')
    <x-breadcrumbs :items="$breadcrumbs" />

    <header class="mb-10 space-y-3">
        <h1 class="font-serif text-4xl tracking-tight text-plum sm:text-5xl">
            {{ \App\Support\Terminology::giftIdeas() }}
        </h1>
        <p class="max-w-3xl text-base leading-relaxed text-ink-muted">
            Browse gifts by recipient, occasion, interest, profession, gift type, or category.
        </p>
    </header>

    @if ($recipientTypes->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 font-serif text-xl text-plum">Recipients</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($recipientTypes as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::recipientType($item->slug) }}" class="inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm text-ink hover:border-plum hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($relationships->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 font-serif text-xl text-plum">{{ \App\Support\Terminology::gifts() }} for</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($relationships as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::relationship($item->slug) }}" class="inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm text-ink hover:border-plum hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($occasions->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 font-serif text-xl text-plum">Occasions</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($occasions as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::occasion($item->slug) }}" class="inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm text-ink hover:border-plum hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($interests->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 font-serif text-xl text-plum">Interests</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($interests as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::interest($item->slug) }}" class="inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm text-ink hover:border-plum hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($professions->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 font-serif text-xl text-plum">Professions</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($professions as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::profession($item->slug) }}" class="inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm text-ink hover:border-plum hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($giftTypes->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 font-serif text-xl text-plum">Gift Types</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($giftTypes as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::giftType($item->slug) }}" class="inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm text-ink hover:border-plum hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($categories->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 font-serif text-xl text-plum">Categories</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($categories as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::giftIdeasCategory($item->full_path) }}" class="inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm text-ink hover:border-plum hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
