@props([
    'as' => 'div',
])

<{{ $as }} {{ $attributes->merge(['class' => 'mx-auto w-full max-w-[1280px] px-5 md:px-8']) }}>
    {{ $slot }}
</{{ $as }}>
