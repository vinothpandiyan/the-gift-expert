@extends('layouts.public')

@section('title', $seoTitle)

@section('content')
    <x-breadcrumbs :items="$breadcrumbs" />

    <header class="mb-8 space-y-3">
        <h1 class="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
            {{ \App\Support\Terminology::giftIdeas() }}
        </h1>
        <p class="max-w-3xl text-base leading-relaxed text-stone-600">
            Browse gifts by recipient, occasion, interest, or category.
        </p>
    </header>

    @if ($recipientTypes->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 text-lg font-semibold text-stone-900">Recipients</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($recipientTypes as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::recipientType($item->slug) }}" class="inline-flex rounded-full border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-700 hover:border-stone-400 hover:bg-stone-50">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($relationships->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 text-lg font-semibold text-stone-900">{{ \App\Support\Terminology::gifts() }} for</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($relationships as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::relationship($item->slug) }}" class="inline-flex rounded-full border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-700 hover:border-stone-400 hover:bg-stone-50">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($occasions->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 text-lg font-semibold text-stone-900">Occasions</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($occasions as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::occasion($item->slug) }}" class="inline-flex rounded-full border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-700 hover:border-stone-400 hover:bg-stone-50">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($interests->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 text-lg font-semibold text-stone-900">Interests</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($interests as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::interest($item->slug) }}" class="inline-flex rounded-full border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-700 hover:border-stone-400 hover:bg-stone-50">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($categories->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 text-lg font-semibold text-stone-900">Categories</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($categories as $item)
                    <li>
                        <a href="{{ \App\Support\DiscoveryUrl::giftIdeasCategory($item->full_path) }}" class="inline-flex rounded-full border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-700 hover:border-stone-400 hover:bg-stone-50">
                            {{ $item->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
