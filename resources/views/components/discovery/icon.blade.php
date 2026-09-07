@props([
    'name' => 'gift',
    'size' => 'md',
])

@php
    $class = $size === 'sm' ? 'size-4' : 'size-5';
@endphp

@switch($name)
    @case('birthday')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c.5-1.5 1.5-3 0-4M6 11h12v9a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-9Zm0 0V9a3 3 0 0 1 3-3h6a3 3 0 0 1 3 3v2M12 11v10" />
        </svg>
        @break
    @case('anniversary')
    @case('wedding')
    @case('engagement')
    @case('valentines-day')
    @case('mothers-day')
    @case('fathers-day')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6.5-4.35-9-8.5C1.5 9.5 3 6 6.5 6 8.7 6 10.2 7.2 12 9c1.8-1.8 3.3-3 5.5-3C21 6 22.5 9.5 21 12.5 18.5 16.65 12 21 12 21Z" />
        </svg>
        @break
    @case('housewarming')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 11.5 12 5l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-8.5Z" />
        </svg>
        @break
    @case('diwali')
    @case('festival')
    @case('holi')
    @case('raksha-bandhan')
    @case('pongal')
    @case('eid')
    @case('christmas')
    @case('new-year')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c.8 3 3 5.2 3 8.2A3 3 0 1 1 9 11.2C9 8.2 11.2 6 12 3Zm-6.5 14.5c2.2-1 4.2-1.5 6.5-1.5s4.3.5 6.5 1.5M8 20c1.3-.4 2.6-.6 4-.6s2.7.2 4 .6" />
        </svg>
        @break
    @case('coffee')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h11v7a4 4 0 0 1-4 4H9a4 4 0 0 1-4-4V8Zm11 1h2.5A2.5 2.5 0 0 1 21 11.5v0A2.5 2.5 0 0 1 18.5 14H16M4 20h13" />
        </svg>
        @break
    @case('travel')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 14 3 11l1.5-1.5L12 12l8-8-1 5 3 1-9 9-1-3-5 2L6 17l4-3Z" />
        </svg>
        @break
    @case('technology')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <rect x="3" y="5" width="18" height="12" rx="2" />
            <path stroke-linecap="round" d="M8 19h8" />
        </svg>
        @break
    @case('books')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 5.5A2.5 2.5 0 0 1 7.5 3H19v16H7.5A2.5 2.5 0 0 0 5 21.5V5.5ZM8 7h8M8 11h8" />
        </svg>
        @break
    @case('fitness')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 9v6M9 7v10M15 7v10M18 9v6M6 12h12" />
        </svg>
        @break
    @case('music')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 18V6l10-2v12M9 18a3 3 0 1 1-3-3m13 1a3 3 0 1 1-3-3" />
        </svg>
        @break
    @case('photography')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h3l2-2h6l2 2h3v11H4V8Zm8 3.5a3 3 0 1 1 0 6 3 3 0 0 1 0-6Z" />
        </svg>
        @break
    @case('pets')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <circle cx="7" cy="8" r="1.5" /><circle cx="12" cy="6.5" r="1.5" /><circle cx="17" cy="8" r="1.5" /><circle cx="9" cy="12" r="1.4" /><path stroke-linecap="round" d="M8 16.5c1.2-1 2.6-1.5 4-1.5s2.8.5 4 1.5" />
        </svg>
        @break
    @case('food')
    @case('cooking')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 11h16M6 11c0 5 2 9 6 9s6-4 6-9M8 7V4m4 3V4m4 3V4" />
        </svg>
        @break
    @default
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v13m0-13V6a2 2 0 1 1 4 0v1.5M12 8V6a2 2 0 1 0-4 0v1.5m4 .5H5.5A1.5 1.5 0 0 0 4 9.5v10A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5v-10A1.5 1.5 0 0 0 18.5 8H12Z" />
        </svg>
@endswitch
