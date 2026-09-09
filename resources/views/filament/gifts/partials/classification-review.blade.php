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

        <section class="flex min-w-0 items-start gap-4" data-review-identity>
            <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800">
                @if ($review->imageUrl)
                    <img src="{{ $review->imageUrl }}" alt="" class="h-full w-full object-contain">
                @else
                    <span class="px-2 text-center text-[11px] text-gray-400">No image</span>
                @endif
            </div>
            <div class="min-w-0 flex-1 space-y-1">
                <p class="text-base font-semibold leading-6 text-gray-950 [overflow-wrap:break-word] dark:text-white">{{ $review->title }}</p>
                <p class="text-sm leading-6 text-gray-600 [overflow-wrap:break-word] dark:text-gray-300">
                    {{ $review->merchantName ?? 'Unknown merchant' }}
                    · {{ $review->priceDisplay ?? 'No price' }}
                    · {{ $review->availabilityLabel }}
                    · Gift {{ $review->productStatus }}
                </p>
                @if (filled($review->externalProductId))
                    <p class="font-mono text-xs text-gray-500 [overflow-wrap:break-word]">{{ $review->externalProductId }}</p>
                @endif
            </div>
        </section>

        <section
            class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
            data-review-status
        >
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Status</h3>
            <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-3">
                <div>
                    <p class="text-xs text-gray-500">Classification</p>
                    <div class="mt-1">
                        <x-filament::badge :color="$review->classificationStatus->getColor()">
                            {{ $review->classificationStatus->getLabel() }}
                        </x-filament::badge>
                    </div>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Version</p>
                    <p class="mt-1 text-sm font-medium">{{ $review->classificationVersion ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Classified at</p>
                    <p class="mt-1 text-sm">{{ $review->classifiedAt ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Approved at</p>
                    <p class="mt-1 text-sm">{{ $review->approvedAt ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Approved by</p>
                    <p class="mt-1 text-sm">{{ $review->approvedBy ?? '—' }}</p>
                </div>
            </div>
        </section>

        @if ($review->reviewReasons !== [] || filled($review->gapSuggestion) || filled($review->gapExplanation) || $review->reasoningBlocks !== [])
            <section
                @class([
                    'min-w-0 rounded-xl border p-4',
                    'border-danger-200 bg-danger-50/60 dark:border-danger-500/30 dark:bg-danger-500/10' => $statusTone === 'danger',
                    'border-warning-200 bg-warning-50/60 dark:border-warning-500/30 dark:bg-warning-500/10' => $statusTone === 'warning',
                    'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' => ! in_array($statusTone, ['danger', 'warning'], true),
                ])
                data-why-review
            >
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                    @if ($review->classificationStatus === TaxonomyClassificationStatus::Failed)
                        Why classification failed
                    @elseif ($review->reviewReasons !== [])
                        Why review is needed
                    @else
                        Classification notes
                    @endif
                </h3>

                @if ($review->reviewReasons !== [])
                    <p class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ $review->classificationStatus->getLabel() }}
                    </p>
                    <ul class="mt-2 list-disc space-y-1.5 pl-5 text-sm leading-6 text-gray-800 dark:text-gray-200">
                        @foreach ($review->reviewReasons as $reason)
                            <li class="[overflow-wrap:break-word]" title="{{ $reason['description'] }} ({{ $reason['code'] }})">
                                {{ $reason['label'] }}
                            </li>
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
                                    <span title="{{ $warning['description'] }} ({{ $warning['code'] }})" class="inline-flex max-w-full rounded-full bg-white px-2.5 py-1 text-xs font-medium leading-5 text-gray-700 ring-1 ring-inset ring-gray-500/20 [overflow-wrap:break-word] dark:bg-gray-800 dark:text-gray-200">
                                        {{ $warning['label'] }}
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
                            <p class="mt-1 text-sm font-semibold leading-6 [overflow-wrap:break-word]">{{ $review->gapSuggestion }}</p>
                        @endif
                        @if (filled($review->gapExplanation))
                            <p class="mt-1 text-sm leading-6 text-gray-700 [overflow-wrap:break-word] dark:text-gray-300">{{ $review->gapExplanation }}</p>
                        @endif
                    </div>
                @endif

                @if ($review->reasoningBlocks !== [])
                    <div class="mt-4 space-y-3" data-ai-reasoning>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">AI reasoning</p>
                        @foreach ($review->reasoningBlocks as $block)
                            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-950">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $block['label'] }}</p>
                                <p class="mt-1 text-sm leading-6 text-gray-800 [overflow-wrap:break-word] dark:text-gray-200">{{ $block['text'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        <div class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-2" data-proposal-vs-applied>
            <div class="min-w-0 rounded-xl border border-dashed border-primary-300 bg-primary-50/40 p-4 dark:border-primary-500/40 dark:bg-primary-500/5" data-ai-proposal>
                <h3 class="text-sm font-semibold text-primary-800 dark:text-primary-200">AI proposal</h3>
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
                                        <span @class([
                                            'inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset',
                                            'bg-danger-50 text-danger-700 ring-danger-600/20' => $dimension['below_threshold'],
                                            'bg-success-50 text-success-700 ring-success-600/20' => ! $dimension['below_threshold'],
                                        ])>
                                            {{ $dimension['confidence'] }}
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @forelse ($dimension['items'] as $item)
                                        <span class="inline-flex max-w-full rounded-full bg-white px-2.5 py-1 text-xs font-medium leading-5 text-gray-800 ring-1 ring-inset ring-primary-600/20 [overflow-wrap:break-word] dark:bg-gray-900 dark:text-gray-100">
                                            {{ $item['name'] }}
                                        </span>
                                    @empty
                                        <span class="text-sm text-gray-400">—</span>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-applied-taxonomy>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Applied taxonomy</h3>
                <p class="mt-1 text-xs leading-5 text-gray-500">Taxonomy currently stored on this gift.</p>
                <div class="mt-4 space-y-4">
                    @foreach ($review->appliedDimensions as $dimension)
                        <div class="min-w-0">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $dimension['label'] }}</p>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                @forelse ($dimension['items'] as $item)
                                    <span class="inline-flex max-w-full rounded-full bg-gray-50 px-2.5 py-1 text-xs font-medium leading-5 text-gray-800 ring-1 ring-inset ring-gray-500/20 [overflow-wrap:break-word] dark:bg-gray-800 dark:text-gray-100">
                                        {{ $item['name'] }}
                                    </span>
                                @empty
                                    <span class="text-sm text-gray-400">—</span>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-trusted-hints>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Trusted source hints</h3>
            <p class="mt-1 text-xs leading-5 text-gray-500">Wishlist provenance. Historical evidence, not an immutable taxonomy constraint. Hints increase Relationship eligibility; they should not distort Category selection.</p>
            @if ($review->trustedHintNames === [])
                <p class="mt-3 text-sm text-gray-400">No trusted relationship hints.</p>
            @else
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($review->trustedHintNames as $hint)
                        <span class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium leading-5 text-amber-800 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200">
                            {{ $hint }}
                        </span>
                    @endforeach
                </div>
            @endif

            <p class="mt-4 text-xs font-medium uppercase tracking-wide text-gray-500">AI proposed Relationships</p>
            @if ($review->proposedRelationshipNames === [])
                <p class="mt-2 text-sm text-gray-400">None proposed.</p>
            @else
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($review->proposedRelationshipNames as $name)
                        <span class="inline-flex rounded-full bg-gray-50 px-2.5 py-1 text-xs font-medium leading-5 text-gray-800 ring-1 ring-inset ring-gray-500/20 dark:bg-gray-800 dark:text-gray-100">
                            {{ $name }}
                        </span>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-source-provenance>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Source provenance</h3>
            <p class="mt-1 text-xs leading-5 text-gray-500">Read-only wishlist history. Source mappings are managed in config, not here.</p>
            @if ($review->provenance === [])
                <p class="mt-3 text-sm text-gray-400">No source lists recorded.</p>
            @else
                <div class="mt-3 overflow-x-auto">
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
                                    <td class="py-2 pr-4 font-medium [overflow-wrap:break-word]">{{ $source['name'] }}</td>
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
        </section>
    </div>
@endif
