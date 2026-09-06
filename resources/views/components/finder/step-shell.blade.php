@props([
    'title',
    'subtitle' => null,
    'error' => null,
    'errorId' => null,
])

<div>
    <h1 class="font-serif text-[28px] leading-tight tracking-tight text-ink md:text-[38px]">{{ $title }}</h1>
    @if (filled($subtitle))
        <p class="mt-2 text-[15px] text-ink-muted">{{ $subtitle }}</p>
    @endif
    <div class="mt-6">
        {{ $slot }}
    </div>
    @if (filled($error))
        <p
            @if (filled($errorId))
                id="{{ $errorId }}"
            @endif
            class="mt-4 text-sm text-coral"
            role="alert"
        >
            {{ $error }}
        </p>
    @endif
</div>
