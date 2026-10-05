<div
    x-data
    x-on:finder-step-changed.window="$nextTick(() => document.querySelector('[data-finder-heading]')?.focus())"
    {{-- Only `step` pushes history; answers replace the current entry. Livewire restores `step` on
         Back/Forward but not replace-mode properties, so re-apply the answers from the restored URL. --}}
    x-on:popstate.window="
        if (! $event.state?.alpine) return;
        const query = new URLSearchParams(window.location.search);
        @js(\App\Livewire\GiftFinder::URL_ANSWER_PROPERTIES).forEach((property) => $wire.set(property, query.get(property) ?? ''));
    "
>
<div class="mx-auto w-full max-w-3xl pb-36 md:pb-8">
    <div class="text-center">
        <span class="inline-flex items-center gap-2 rounded-md bg-plum-light px-3 py-1.5 text-[12px] font-semibold tracking-wide text-plum">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5" aria-hidden="true">
                <path d="M12 2.5 13.2 8l5.8.4-4.4 3.6 1.4 5.5L12 14.8 7.99 17.5 9.4 12 5 8.4 10.8 8 12 2.5Z" />
            </svg>
            Gift Finder
        </span>
    </div>

    <div class="mt-6">
        <x-finder.progress :step="$step" :total="4" :label="$this->stepLabel()" />
    </div>

    <div class="mt-4">
        <x-finder.selection-summary :items="$this->summaryItems()" :current-step="$step" />
    </div>

    <div class="mt-8" wire:key="finder-step-{{ $step }}">
        @if ($step === 1)
            <x-finder.step-shell
                title="Who are you buying for?"
                subtitle="Pick the closest relationship."
                :error="$stepError"
                error-id="finder-step-error"
            >
                <div
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3"
                    role="radiogroup"
                    aria-label="Who are you buying for?"
                    @if (filled($stepError))
                        aria-describedby="finder-step-error"
                    @endif
                >
                    @foreach ($this->relationships as $relationship)
                        <x-finder.option-card
                            :label="$relationship->name"
                            :note="filled($relationship->description) ? $relationship->description : null"
                            :selected="(int) $relationship_id === (int) $relationship->id"
                            wire:click="selectRelationship({{ $relationship->id }})"
                        />
                    @endforeach
                </div>
            </x-finder.step-shell>
        @elseif ($step === 2)
            <x-finder.step-shell
                title="What's the occasion?"
                subtitle="This shapes the tone of the gift more than anything else."
                :error="$stepError"
                error-id="finder-step-error"
            >
                <div
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3"
                    role="radiogroup"
                    aria-label="What's the occasion?"
                    @if (filled($stepError))
                        aria-describedby="finder-step-error"
                    @endif
                >
                    @foreach ($this->occasions as $occasion)
                        <x-finder.option-card
                            :label="$occasion->name"
                            :note="filled($occasion->description) ? $occasion->description : null"
                            :selected="(int) $occasion_id === (int) $occasion->id"
                            wire:click="selectOccasion({{ $occasion->id }})"
                        />
                    @endforeach
                </div>
            </x-finder.step-shell>
        @elseif ($step === 3)
            <x-finder.step-shell
                title="What's your budget?"
                subtitle="We'll stay inside it — or choose Any budget for no limit."
                :error="$stepError"
                error-id="finder-step-error"
            >
                <div
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3"
                    role="radiogroup"
                    aria-label="What's your budget?"
                    @if (filled($stepError))
                        aria-describedby="finder-step-error"
                    @endif
                >
                    @foreach ($this->budgetRanges as $budgetRange)
                        <x-finder.option-card
                            :label="$budgetRange->name"
                            :selected="(int) $budget_range_id === (int) $budgetRange->id"
                            wire:click="selectBudget({{ $budgetRange->id }})"
                        />
                    @endforeach
                    <x-finder.option-card
                        label="Any budget"
                        note="No price limit"
                        :selected="$any_budget && $budget_range_id === null"
                        wire:click="selectAnyBudget"
                    />
                </div>
            </x-finder.step-shell>
        @else
            @php
                $maxInterests = $this->maxInterests();
                $selectedCount = $this->selectedInterestCount();
            @endphp
            <x-finder.step-shell
                title="What are they into?"
                :subtitle="'Optional — choose up to '.$maxInterests.' to personalize the results.'"
                :error="$stepError"
                error-id="finder-step-error"
            >
                <fieldset
                    @if (filled($stepError))
                        aria-describedby="finder-step-error"
                    @endif
                >
                    <legend class="sr-only">Interests, choose up to {{ $maxInterests }}</legend>
                    <div class="flex flex-wrap gap-2.5">
                        @foreach ($this->interests as $interest)
                            @php
                                $selected = $this->interestIsSelected((int) $interest->id);
                                $disabled = ! $selected && $selectedCount >= $maxInterests;
                            @endphp
                            <x-finder.option-chip
                                :label="$interest->name"
                                :selected="$selected"
                                :disabled="$disabled"
                                wire:click="toggleInterest({{ $interest->id }})"
                            />
                        @endforeach
                    </div>
                </fieldset>
                <p class="mt-4 text-[13px] text-ink-muted" role="status" aria-live="polite">
                    {{ $selectedCount }} of {{ $maxInterests }} selected{{ $selectedCount >= $maxInterests ? ' — deselect one to choose another' : '' }}
                </p>
            </x-finder.step-shell>
        @endif
    </div>

    @php
        $findLabel = $this->selectedInterestCount() === 0 ? 'Skip — show me all suitable gifts' : 'Find gifts';
    @endphp

    <div class="mt-10 hidden items-center justify-between md:flex">
        <x-ui.button
            variant="ghost"
            wire:click="back"
            :disabled="$step === 1"
            aria-label="Back"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
            Back
        </x-ui.button>

        <div class="flex items-center gap-3">
            @if ($step === 4)
                <x-ui.button
                    variant="primary"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                    :disabled="$finding"
                >
                    <span wire:loading.remove wire:target="submit">{{ $findLabel }}</span>
                    <span wire:loading wire:target="submit">Finding gifts...</span>
                    <svg wire:loading.remove wire:target="submit" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </x-ui.button>
            @else
                <x-ui.button
                    variant="primary"
                    wire:click="continueStep"
                    :disabled="! $this->canContinue()"
                >
                    Continue
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </x-ui.button>
            @endif
        </div>
    </div>
</div>

<div class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:hidden">
    <div class="flex items-center gap-3">
        <x-ui.button
            variant="secondary"
            wire:click="back"
            :disabled="$step === 1"
            aria-label="Back"
            class="w-14 px-0"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </x-ui.button>

        @if ($step === 4)
            <x-ui.button
                variant="primary"
                wire:click="submit"
                wire:loading.attr="disabled"
                wire:target="submit"
                :disabled="$finding"
                class="flex-1 text-[14px]"
            >
                <span wire:loading.remove wire:target="submit">{{ $findLabel }}</span>
                <span wire:loading wire:target="submit">Finding gifts...</span>
            </x-ui.button>
        @else
            <x-ui.button
                variant="primary"
                wire:click="continueStep"
                :disabled="! $this->canContinue()"
                class="flex-1"
            >
                Continue
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </x-ui.button>
        @endif
    </div>
</div>
</div>
