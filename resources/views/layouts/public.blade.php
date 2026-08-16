<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $title ?? config('app.name'))</title>
    <x-seo-meta
        :description="$seoDescription ?? null"
        :canonical="$seoCanonical ?? null"
        :robots="$seoRobots ?? 'index, follow'"
        :prev="$seoPrev ?? null"
        :next="$seoNext ?? null"
    />
    @fonts
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>
            @import url('https://fonts.bunny.net/css?family=instrument-sans:400,500,600');
            body { font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif; margin: 0; background: #fafaf9; color: #1c1917; }
            a { color: inherit; }
        </style>
    @endif
</head>
<body class="min-h-screen bg-stone-50 text-stone-900 antialiased">
    <x-site-header />

    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-10">
        @yield('content')
    </main>

    <x-site-footer />
</body>
</html>
