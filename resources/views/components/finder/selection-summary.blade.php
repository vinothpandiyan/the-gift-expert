@props([
    'items',
    'currentStep' => null,
])

@if ($items !== [])
    <section aria-labelledby="finder-selection-summary-heading" class="rounded-lg border border-line bg-surface px-3 py-3 md:px-4">
        <h2 id="finder-selection-summary-heading" class="text-[12px] font-semibold tracking-wide text-ink-muted uppercase">
            Your picks <span class="font-normal normal-case tracking-normal">· tap to change</span>
        </h2>
        <ul class="mt-2 flex flex-wrap gap-2">
            @foreach ($items as $item)
                <li class="flex items-center">
                    @if ($item['step'] !== null)
                        <button
                            type="button"
                            wire:click="editStep({{ $item['step'] }})"
                            @if ($currentStep === $item['step']) aria-current="step" @endif
                            @class([
                                'inline-flex min-h-11 items-center gap-1.5 rounded-md border px-3 text-[14px] font-medium transition-colors',
                                'border-plum bg-plum-light text-plum' => $currentStep === $item['step'],
                                'border-line bg-surface text-ink hover:border-plum/40 hover:bg-plum-light/50' => $currentStep !== $item['step'],
                            ])
                        >
                            <span class="sr-only">{{ $item['change'] }}: </span>
                            <span>{{ $item['value'] }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3.5 w-3.5 shrink-0 text-ink-muted" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.651 1.651a1.875 1.875 0 010 2.652L9.75 17.553 5.25 18.75l1.197-4.5 8.763-8.763a1.875 1.875 0 012.652 0z" />
                            </svg>
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="clearAnswer('{{ $item['clear'] }}')"
                            class="inline-flex min-h-11 items-center gap-1.5 rounded-md border border-line bg-surface px-3 text-[14px] font-medium text-ink transition-colors hover:border-plum/40 hover:bg-plum-light/50"
                        >
                            <span class="sr-only">{{ $item['change'] }}: </span>
                            <span>{{ $item['value'] }}</span>
                            <span aria-hidden="true" class="text-ink-muted">&times;</span>
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
