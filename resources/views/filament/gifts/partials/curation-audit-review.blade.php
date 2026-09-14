@php
    use App\Actions\CatalogCuration\BuildProductCurationAuditReviewAction;

    $record = $reviewProduct ?? (function_exists('getRecord') ? $getRecord() : null);
    $review = $record
        ? app(BuildProductCurationAuditReviewAction::class)->execute($record)
        : null;
@endphp

<div class="min-w-0" data-curation-audit-review data-review-layout="full-width">
    @if (! $review)
        <div class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center dark:border-gray-700" data-curation-audit-empty>
            <p class="text-sm font-medium text-gray-950 dark:text-white">No completed curation audit</p>
            <p class="mt-1 text-sm text-gray-500">This Gift has no completed catalog curation result to review yet.</p>
        </div>
    @else
        <div class="space-y-5">
            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-curation-summary>
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Recommendation</dt>
                        <dd class="mt-1">
                            <x-filament::badge :color="$review->recommendationColor">
                                {{ $review->recommendation }}
                            </x-filament::badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Human review required</dt>
                        <dd class="mt-1">
                            <x-filament::badge :color="$review->requiresHumanReview ? 'warning' : 'success'">
                                {{ $review->requiresHumanReview ? 'Yes' : 'No' }}
                            </x-filament::badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">AI confidence</dt>
                        <dd class="mt-1">
                            <x-filament::badge :color="$review->confidenceColor">
                                {{ $review->confidence }}
                            </x-filament::badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Concept</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $review->conceptLabel ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">GiftIntents</dt>
                        <dd class="mt-1">
                            @include('filament.gifts.partials.review-chips', ['items' => $review->intents, 'empty' => 'None recorded.'])
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-gift-score-card>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Gift Score</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $review->giftScore ?? '—' }} <span class="text-lg font-medium text-gray-400">/ 100</span></p>
                    @if ($review->giftScoreBand)
                        <p class="mt-2">
                            <x-filament::badge :color="match ($review->giftScoreBand) {
                                'Strong' => 'success',
                                'Moderate' => 'warning',
                                default => 'gray',
                            }">{{ $review->giftScoreBand }}</x-filament::badge>
                        </p>
                    @endif
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-catalog-value-card>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Catalog Value</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $review->catalogValueScore ?? '—' }} <span class="text-lg font-medium text-gray-400">/ 100</span></p>
                    @if ($review->catalogValueBand)
                        <p class="mt-2">
                            <x-filament::badge :color="match ($review->catalogValueBand) {
                                'Strong' => 'success',
                                'Moderate' => 'warning',
                                default => 'gray',
                            }">{{ $review->catalogValueBand }}</x-filament::badge>
                        </p>
                    @endif
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-why-this-gift>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Why this gift?</h3>
                <p class="mt-3 max-w-3xl whitespace-pre-wrap text-sm leading-7 text-gray-800 dark:text-gray-200">{{ $review->whyThisGift ?? 'No rationale recorded.' }}</p>
            </section>

            <section class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-2" data-strengths-concerns>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Strengths</h3>
                    <ul class="mt-3 space-y-2 text-sm text-gray-800 dark:text-gray-200">
                        @forelse ($review->strengths as $strength)
                            <li class="flex gap-2">
                                <span class="text-success-600 dark:text-success-400">✓</span>
                                <span>{{ $strength }}</span>
                            </li>
                        @empty
                            <li class="text-gray-400">None recorded.</li>
                        @endforelse
                    </ul>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-curation-issues>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Concerns</h3>
                    @if ($review->concerns === [])
                        <p class="mt-3 text-sm text-gray-400">None recorded.</p>
                    @else
                        <p class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Review reasons</p>
                        <ul class="mt-2 space-y-2 text-sm text-gray-800 dark:text-gray-200">
                            @foreach ($review->concerns as $concern)
                                <li class="flex gap-2" title="{{ $concern['code'] }}">
                                    <span>•</span>
                                    <span>
                                        <span class="mr-2 inline-flex align-middle">
                                            <x-filament::badge :color="match ($concern['severity']) {
                                                'blocking', 'critical' => 'danger',
                                                'material', 'warning' => 'warning',
                                                default => 'info',
                                            }" size="sm">{{ str($concern['severity'])->headline() }}</x-filament::badge>
                                        </span>
                                        <span class="font-medium">{{ $concern['label'] }}</span>
                                        @if ($concern['message'] !== $concern['label'])
                                            <span class="mt-0.5 block text-gray-600 dark:text-gray-300">{{ $concern['message'] }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            <section class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-2" data-curation-factor-breakdown>
                @include('filament.gifts.partials.review-score-table', [
                    'heading' => 'Gift Score breakdown',
                    'factors' => $review->giftFactors,
                ])
                @include('filament.gifts.partials.review-score-table', [
                    'heading' => 'Catalog Value breakdown',
                    'factors' => $review->catalogFactors,
                ])
            </section>

            <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-taxonomy-findings data-curation-fit-comparison>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Taxonomy findings</h3>
                <p class="mt-1 text-xs text-gray-500">Advisory audit findings only; no taxonomy is changed here.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="pb-2 pr-4 font-medium">Dimension</th>
                                <th class="pb-2 pr-4 font-medium">Current</th>
                                <th class="pb-2 font-medium">Audit finding</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($review->fitRows as $row)
                                <tr>
                                    <td class="py-3 pr-4 align-top font-medium">{{ $row['dimension'] }}</td>
                                    <td class="py-3 pr-4 align-top">
                                        @include('filament.gifts.partials.review-chips', ['items' => $row['current']])
                                    </td>
                                    <td class="py-3 align-top">
                                        @if (($row['currentItems'] ?? []) === [] && ($row['auditItems'] ?? []) === [] && ($row['differences'] ?? []) === [])
                                            <span class="text-gray-400">—</span>
                                        @else
                                            <div class="space-y-2">
                                                @foreach ($row['currentItems'] ?? [] as $item)
                                                    <div>
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            <span>{{ $item['name'] }}</span>
                                                            @if ($item['strength_label'])
                                                                <x-filament::badge :color="$item['strength_color']" size="sm">
                                                                    {{ $item['strength_label'] }}
                                                                </x-filament::badge>
                                                            @endif
                                                        </div>
                                                        @if (filled($item['reason']))
                                                            <p class="mt-1 max-w-xl text-xs leading-5 text-gray-500">{{ $item['reason'] }}</p>
                                                        @endif
                                                    </div>
                                                @endforeach
                                                @foreach ($row['auditItems'] ?? [] as $item)
                                                    @continue(in_array($item['name'], $row['current'] ?? [], true))
                                                    <div>
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            <span>{{ $item['name'] }}</span>
                                                            @if ($item['strength_label'])
                                                                <x-filament::badge :color="$item['strength_color']" size="sm">
                                                                    {{ $item['strength_label'] }}
                                                                </x-filament::badge>
                                                            @endif
                                                            <x-filament::badge color="info" size="sm">Suggested</x-filament::badge>
                                                        </div>
                                                        @if (filled($item['reason']))
                                                            <p class="mt-1 max-w-xl text-xs leading-5 text-gray-500">{{ $item['reason'] }}</p>
                                                        @endif
                                                    </div>
                                                @endforeach
                                                @foreach ($row['differences'] as $difference)
                                                    <div class="flex flex-wrap items-center gap-1.5">
                                                        <x-filament::badge :color="match ($difference['severity']) {
                                                            'blocking' => 'danger',
                                                            'material' => 'warning',
                                                            default => 'info',
                                                        }" size="sm">{{ $difference['action_label'] ?? str($difference['severity'])->headline() }}</x-filament::badge>
                                                        @if (filled($difference['name']))
                                                            <span>{{ $difference['name'] }}</span>
                                                        @endif
                                                        <x-filament::badge color="gray" size="sm">{{ str($difference['severity'])->headline() }}</x-filament::badge>
                                                        @if ($difference['forces_human_review'])
                                                            <span class="text-xs font-medium text-warning-600 dark:text-warning-400">Forces review</span>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <details class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900" data-catalog-context data-curation-peers>
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-950 dark:text-white">Catalog context</summary>
                <div class="space-y-4 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($review->catalogContext as $item)
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $item['label'] }}</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ $item['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($review->peers as $peer)
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $peer['label'] }}</p>
                                <p class="mt-1 text-sm font-semibold">{{ $peer['count'] }} peers</p>
                                <div class="mt-2">
                                    @include('filament.gifts.partials.review-id-list', ['ids' => $peer['ids']])
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </details>

            <details class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900" data-audit-metadata>
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-950 dark:text-white">Audit metadata</summary>
                <dl class="grid grid-cols-1 gap-3 border-t border-gray-100 px-4 py-3 sm:grid-cols-2 lg:grid-cols-3 dark:border-gray-800">
                    @foreach ($review->metadata as $item)
                        <div class="min-w-0">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $item['label'] }}</dt>
                            <dd class="mt-1 break-all text-sm text-gray-800 dark:text-gray-200">{{ $item['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </details>
        </div>
    @endif
</div>
