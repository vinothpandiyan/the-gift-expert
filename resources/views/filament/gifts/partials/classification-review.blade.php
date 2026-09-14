@php
    use App\Actions\CuratedCatalog\BuildProductTaxonomyClassificationReviewAction;
    use App\Enums\TaxonomyClassificationStatus;

    $record = $reviewProduct ?? (function_exists('getRecord') ? $getRecord() : null);
    $review = $record
        ? app(BuildProductTaxonomyClassificationReviewAction::class)->execute($record)
        : null;

    $statusTone = match ($review?->classificationStatus) {
        TaxonomyClassificationStatus::Failed => 'danger',
        TaxonomyClassificationStatus::Review, TaxonomyClassificationStatus::AiProposed => 'warning',
        TaxonomyClassificationStatus::AiAccepted, TaxonomyClassificationStatus::HumanApproved => 'success',
        default => 'gray',
    };

    $reasonHeading = match ($review?->classificationStatus) {
        TaxonomyClassificationStatus::Failed => 'Why classification failed',
        default => $review?->reviewReasons !== [] ? 'Why review is needed' : 'Classification notes',
    };
@endphp

@if ($review)
    <div class="min-w-0 space-y-5" data-classification-review data-review-layout="full-width">
        @if ($review->proposalStale)
            <div
                class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200"
                data-stale-proposal
            >
                <p class="font-medium">Proposal may be stale — reclassification recommended</p>
                <p class="mt-1 text-sm leading-6">Taxonomy version, source title, or relationship hints changed since this proposal was generated. Approval is blocked until you reclassify.</p>
            </div>
        @endif

        @if ($review->proposalPending)
            <div
                class="rounded-lg border border-info-200 bg-info-50 px-4 py-3 text-sm text-info-800 dark:border-info-500/30 dark:bg-info-500/10 dark:text-info-200"
                data-pending-proposal
            >
                <p class="font-medium">A newer AI proposal is waiting for review.</p>
                <p class="mt-1 text-sm leading-6">Applied taxonomy remains human-owned until you approve the proposal or save a manual override.</p>
            </div>
        @endif

        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-review-status>
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Status</dt>
                    <dd class="mt-1">
                        <x-filament::badge :color="$review->classificationStatus->getColor()">
                            {{ $review->classificationStatus->getLabel() }}
                        </x-filament::badge>
                    </dd>
                </div>
                <div data-classification-confidence>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">AI confidence</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-2">
                        @if ($review->confidenceLabel)
                            <x-filament::badge :color="$review->confidenceColor">
                                {{ $review->confidenceLabel }}
                            </x-filament::badge>
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ $review->confidenceValue }}</span>
                        @else
                            <span class="text-sm text-gray-400">—</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        @if ($review->reviewReasons !== [] || filled($review->gapSuggestion) || filled($review->gapExplanation) || $review->warnings !== [])
            <section
                @class([
                    'min-w-0 rounded-xl border p-4',
                    'border-danger-200 bg-danger-50/60 dark:border-danger-500/30 dark:bg-danger-500/10' => $statusTone === 'danger',
                    'border-warning-200 bg-warning-50/60 dark:border-warning-500/30 dark:bg-warning-500/10' => $statusTone === 'warning',
                    'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' => ! in_array($statusTone, ['danger', 'warning'], true),
                ])
                data-why-review
            >
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $reasonHeading }}</h3>

                @if ($review->reviewReasons !== [])
                    <p class="mt-2 text-xs font-medium uppercase tracking-wide text-gray-500">
                        {{ count($review->reviewReasons) === 1 ? 'Reason' : 'Reasons' }}
                    </p>
                    <ul class="mt-2 list-disc space-y-1.5 pl-5 text-sm leading-6 text-gray-800 dark:text-gray-200">
                        @foreach ($review->reviewReasons as $reason)
                            <li title="{{ $reason['description'] }} ({{ $reason['code'] }})">{{ $reason['label'] }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($review->warnings !== [])
                    @php
                        $warningOnly = collect($review->warnings)
                            ->reject(fn (array $warning): bool => collect($review->reviewReasons)->contains('code', $warning['code']))
                            ->values()
                            ->all();
                    @endphp
                    @if ($warningOnly !== [])
                        <div class="mt-4" data-review-warnings>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Warnings</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($warningOnly as $warning)
                                    <span title="{{ $warning['description'] }} ({{ $warning['code'] }})">
                                        <x-filament::badge color="warning" size="sm">
                                            {{ $warning['label'] }}
                                        </x-filament::badge>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif

                @if (filled($review->gapSuggestion) || filled($review->gapExplanation))
                    <div class="mt-4 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-950" data-taxonomy-gap>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Taxonomy gap</p>
                        @if (filled($review->gapSuggestion))
                            <p class="mt-1 text-sm font-semibold leading-6">{{ $review->gapSuggestion }}</p>
                        @endif
                        @if (filled($review->gapExplanation))
                            <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-700 dark:text-gray-300">{{ $review->gapExplanation }}</p>
                        @endif
                    </div>
                @endif
            </section>
        @endif

        <div class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-2" data-proposal-vs-applied>
            <div class="min-w-0 rounded-xl border border-dashed border-primary-300 bg-primary-50/40 p-4 dark:border-primary-500/40 dark:bg-primary-500/5" data-ai-proposal>
                <h3 class="text-sm font-semibold text-primary-800 dark:text-primary-200">AI Proposed</h3>
                <p class="mt-1 text-xs leading-5 text-primary-700/80 dark:text-primary-300/80">Not applied until approved. This is not current gift taxonomy.</p>
                @if ($review->proposalDimensions === [])
                    <p class="mt-3 text-sm text-gray-500">No stored proposal.</p>
                @else
                    <div class="mt-4 space-y-4">
                        @foreach ($review->proposalDimensions as $dimension)
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $dimension['label'] }}</p>
                                    @if (filled($dimension['confidence']))
                                        <x-filament::badge :color="$dimension['below_threshold'] ? 'danger' : 'success'" size="sm">
                                            {{ $dimension['confidence'] }}
                                        </x-filament::badge>
                                    @endif
                                </div>
                                <div class="mt-1.5">
                                    @include('filament.gifts.partials.review-chips', ['items' => $dimension['items']])
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-applied-taxonomy>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Current Assignment</h3>
                <p class="mt-1 text-xs leading-5 text-gray-500">Taxonomy currently stored on this gift.</p>
                <div class="mt-4 space-y-4">
                    @foreach ($review->appliedDimensions as $dimension)
                        <div class="min-w-0">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $dimension['label'] }}</p>
                            <div class="mt-1.5">
                                @include('filament.gifts.partials.review-chips', ['items' => $dimension['items']])
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-classification-differences>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Classification Differences</h3>
            @if ($review->differences === [])
                <p class="mt-3 text-sm text-gray-500">No stored proposal to compare against the current assignment.</p>
            @else
            <div class="mt-4 space-y-3">
                @foreach ($review->differences as $difference)
                    <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $difference['label'] }}</p>
                            <x-filament::badge
                                :color="match ($difference['status']) {
                                    'added' => 'success',
                                    'removed' => 'danger',
                                    'conflict' => 'warning',
                                    default => 'gray',
                                }"
                                size="sm"
                            >
                                {{ $difference['status_label'] }}
                            </x-filament::badge>
                        </div>
                        @if ($difference['status'] === 'unchanged')
                            <p class="mt-1 text-sm text-gray-500">No difference</p>
                        @else
                            <div class="mt-2 space-y-1 text-sm">
                                @foreach ($difference['added'] as $name)
                                    <p class="text-success-700 dark:text-success-400">+ {{ $name }}</p>
                                @endforeach
                                @foreach ($difference['removed'] as $name)
                                    <p class="text-danger-700 dark:text-danger-400">- {{ $name }}</p>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            @endif
        </section>

        <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-trusted-hints>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Trusted source hints</h3>
            <p class="mt-1 text-xs leading-5 text-gray-500">Wishlist provenance. Historical evidence, not an immutable taxonomy constraint.</p>
            <p class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Relationships</p>
            <div class="mt-1.5">
                @include('filament.gifts.partials.review-chips', ['items' => $review->trustedHintNames, 'empty' => 'No trusted relationship hints.'])
            </div>
            <p class="mt-4 text-xs font-medium uppercase tracking-wide text-gray-500">AI proposed Relationships</p>
            <div class="mt-1.5">
                @include('filament.gifts.partials.review-chips', ['items' => $review->proposedRelationshipNames, 'empty' => 'None proposed.'])
            </div>
        </section>

        @if ($review->reasoningBlocks !== [])
            <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-ai-reasoning>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">AI reasoning</h3>
                <div class="mt-3 space-y-3">
                    @foreach ($review->reasoningBlocks as $block)
                        <div class="max-w-3xl rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-950">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $block['label'] }}</p>
                            <p class="mt-2 whitespace-pre-wrap text-sm leading-7 text-gray-800 dark:text-gray-200">{{ $block['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <details class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900" data-classification-metadata>
            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-950 dark:text-white">Classification metadata</summary>
            <div class="border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($review->metadata as $item)
                        <div class="min-w-0">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $item['label'] }}</dt>
                            <dd class="mt-1 break-all text-sm text-gray-800 dark:text-gray-200">{{ $item['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>

                <h4 class="mt-5 text-xs font-medium uppercase tracking-wide text-gray-500">Source provenance</h4>
                @if ($review->provenance === [])
                    <p class="mt-2 text-sm text-gray-400">No source lists recorded.</p>
                @else
                    <div class="mt-2 overflow-x-auto" data-source-provenance>
                        <table class="min-w-full text-left text-sm">
                            <thead class="text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="pb-2 pr-4 font-medium">Wishlist</th>
                                    <th class="pb-2 pr-4 font-medium">Kind</th>
                                    <th class="pb-2 pr-4 font-medium">Hint</th>
                                    <th class="pb-2 pr-4 font-medium">First seen</th>
                                    <th class="pb-2 font-medium">Last seen</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($review->provenance as $source)
                                    <tr>
                                        <td class="py-2 pr-4 font-medium">{{ $source['name'] }}</td>
                                        <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $source['kind'] }}</td>
                                        <td class="py-2 pr-4">{{ $source['relationship'] ?? '—' }}</td>
                                        <td class="py-2 pr-4 whitespace-nowrap text-gray-500">{{ $source['first_seen'] ?? '—' }}</td>
                                        <td class="py-2 whitespace-nowrap text-gray-500">{{ $source['last_seen'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </details>
    </div>
@endif
