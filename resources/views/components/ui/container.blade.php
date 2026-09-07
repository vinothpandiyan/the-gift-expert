@props([
    'as' => 'div',
])

<{{ $as }} {{ $attributes->merge(['class' => 'mx-auto w-full max-w-page px-5 md:px-8']) }}>
    {{ $slot }}
</{{ $as }}>
