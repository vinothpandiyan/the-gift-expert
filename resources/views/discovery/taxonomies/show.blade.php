@extends('layouts.public')

@section('title', $seoTitle)

@section('content')
    <x-breadcrumbs :items="$breadcrumbs" />

    <header class="mb-8 space-y-3">
        <p class="text-sm font-medium uppercase tracking-wide text-stone-500">
            {{ $taxonomyLabel }}
        </p>
        <h1 class="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
            {{ $taxonomy->name }}
        </h1>
        @if (! empty($taxonomy->description))
            <p class="max-w-3xl text-base leading-relaxed text-stone-600">
                {{ $taxonomy->description }}
            </p>
        @endif
    </header>

    <section>
        <h2 class="mb-4 text-lg font-semibold text-stone-900">
            {{ \App\Support\Terminology::gifts() }}
        </h2>

        @if ($products->isEmpty())
            <p class="text-stone-500">No {{ strtolower(\App\Support\Terminology::gifts()) }} found yet.</p>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($products as $product)
                    <x-gift-card :product="$product" />
                @endforeach
            </div>

            <div class="mt-8">
                {{ $products->links() }}
            </div>
        @endif
    </section>
@endsection
