<div>
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
        <x-finder.progress :step="$step" :label="$this->stepLabel()" />
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
            @php
                $maxInterests = $this->maxInterests();
                $selectedCount = $this->selectedInterestCount();
            @endphp
            <x-finder.step-shell
                title="What are they into?"
                :subtitle="'Choose up to '.$maxInterests.' — '.$selectedCount.'/'.$maxInterests.' selected.'"
                :error="$stepError"
                error-id="finder-step-error"
            >
                <div
                    class="flex flex-wrap gap-2.5"
                    role="group"
                    aria-label="Interests"
                    @if (filled($stepError))
                        aria-describedby="finder-step-error"
                    @endif
                >
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
            </x-finder.step-shell>
        @elseif ($step === 4)
            <x-finder.step-shell
                title="Anything else about them?"
                subtitle="Optional — it helps us rank ideas, but you can skip it."
            >
                <div class="space-y-6">
                    <fieldset>
                        <legend class="mb-3 text-[13px] font-semibold text-ink">Who are they?</legend>
                        <div class="flex flex-wrap gap-2.5" role="group" aria-label="Recipient type">
                            @foreach ($this->recipientTypes as $recipientType)
                                <x-finder.option-chip
                                    :label="$recipientType->name"
                                    :selected="(int) $recipient_type_id === (int) $recipientType->id"
                                    wire:click="selectRecipientType({{ $recipientType->id }})"
                                />
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="mb-3 text-[13px] font-semibold text-ink">Profession</legend>
                        <div class="flex flex-wrap gap-2.5" role="group" aria-label="Profession">
                            @foreach ($this->professions as $profession)
                                <x-finder.option-chip
                                    :label="$profession->name"
                                    :selected="(int) $profession_id === (int) $profession->id"
                                    wire:click="selectProfession({{ $profession->id }})"
                                />
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="mb-3 text-[13px] font-semibold text-ink">Gift type</legend>
                        <div class="flex flex-wrap gap-2.5" role="group" aria-label="Gift type">
                            @foreach ($this->giftTypes as $giftType)
                                <x-finder.option-chip
                                    :label="$giftType->name"
                                    :selected="(int) $gift_type_id === (int) $giftType->id"
                                    wire:click="selectGiftType({{ $giftType->id }})"
                                />
                            @endforeach
                        </div>
                    </fieldset>
                </div>
            </x-finder.step-shell>
        @else
            <x-finder.step-shell
                title="What's your budget?"
                subtitle="We'll stay inside it."
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
                </div>
            </x-finder.step-shell>
        @endif
    </div>

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
                <x-ui.button variant="ghost" wire:click="skipAbout">
                    Skip
                </x-ui.button>
            @endif

            @if ($step === 5)
                <x-ui.button
                    variant="primary"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                    :disabled="! $this->canContinue() || $finding"
                >
                    <span wire:loading.remove wire:target="submit">Find gifts</span>
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
            <x-ui.button variant="ghost" wire:click="skipAbout" class="px-4">
                Skip
            </x-ui.button>
        @endif

        @if ($step === 5)
            <x-ui.button
                variant="primary"
                wire:click="submit"
                wire:loading.attr="disabled"
                wire:target="submit"
                :disabled="! $this->canContinue() || $finding"
                class="flex-1"
            >
                <span wire:loading.remove wire:target="submit">Find gifts</span>
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
