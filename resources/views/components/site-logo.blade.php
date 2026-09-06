<a
    href="{{ url('/') }}"
    {{ $attributes->merge(['class' => 'flex items-center gap-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum']) }}
>
    <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-plum text-white" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="size-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v13m0-13V6a2 2 0 1 1 4 0v1.5M12 8V6a2 2 0 1 0-4 0v1.5m4 .5H5.5A1.5 1.5 0 0 0 4 9.5v10A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5v-10A1.5 1.5 0 0 0 18.5 8H12Z" />
        </svg>
    </span>
    <span class="font-serif text-lg leading-none tracking-tight text-ink">
        {{ config('app.name') }}
    </span>
</a>
