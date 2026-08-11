@props([
    'description' => null,
    'canonical' => null,
    'robots' => 'index, follow',
    'prev' => null,
    'next' => null,
])

<meta name="robots" content="{{ $robots }}">

@if (filled($description))
    <meta name="description" content="{{ $description }}">
@endif

@if (filled($canonical))
    <link rel="canonical" href="{{ $canonical }}">
@endif

@if (filled($prev))
    <link rel="prev" href="{{ $prev }}">
@endif

@if (filled($next))
    <link rel="next" href="{{ $next }}">
@endif
