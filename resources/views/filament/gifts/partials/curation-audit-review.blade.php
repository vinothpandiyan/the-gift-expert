@php
    use App\Actions\CatalogCuration\BuildProductCurationAuditReviewAction;

    $record = $reviewProduct ?? (function_exists('getRecord') ? $getRecord() : null);
    $review = $record
        ? app(BuildProductCurationAuditReviewAction::class)->execute($record)
        : null;
@endphp

<div class="min-w-0" data-curation-audit-review>
    @if (! $review)
        <div class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center dark:border-gray-700" data-curation-audit-empty>
            <p class="text-sm font-medium text-gray-950 dark:text-white">No completed curation audit</p>
            <p class="mt-1 text-sm text-gray-500">This Gift has no completed catalog curation result to review yet.</p>
        </div>
    @else
        <div class="space-y-5">
            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-curation-summary>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Audit summary</h3>
                        <p class="mt-1 text-xs text-gray-500">Audit #{{ $review->auditId }} · completed {{ $review->completedAt }}</p>
                    </div>
                    <x-filament::badge :color="$review->requiresHumanReview ? 'warning' : 'success'">
                        {{ $review->requiresHumanReview ? 'Human review required' : 'No human review required' }}
                    </x-filament::badge>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ([
                        ['Gift Score', $review->giftScore ?? '—'],
                        ['Catalog Value', $review->catalogValueScore ?? '—'],
                        ['AI confidence', $review->confidence],
                        ['Recommendation', $review->recommendation],
                        ['Catalog role', $review->catalogRole],
                        ['Concept', $review->conceptLabel ?? '—'],
                    ] as [$label, $value])
                        <div class="min-w-0">
                            <dt class="text-xs text-gray-500">{{ $label }}</dt>
                            <dd class="mt-1 text-sm font-semibold [overflow-wrap:break-word]">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if (filled($review->conceptKey))
                    <p class="mt-3 font-mono text-xs text-gray-500">Concept key: {{ $review->conceptKey }}</p>
                @endif
            </section>

            <section class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Gift rationale</h3>
                    <p class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Intents</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @forelse ($review->intents as $intent)
                            <span class="rounded-full bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-500/10 dark:text-primary-200">{{ $intent }}</span>
                        @empty
                            <span class="text-sm text-gray-400">None recorded.</span>
                        @endforelse
                    </div>
                    <p class="mt-4 text-xs font-medium uppercase tracking-wide text-gray-500">Why this Gift</p>
                    <p class="mt-1 text-sm leading-6 text-gray-800 dark:text-gray-200">{{ $review->whyThisGift ?? 'No rationale recorded.' }}</p>
                    <p class="mt-4 text-xs font-medium uppercase tracking-wide text-gray-500">Strengths</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-800 dark:text-gray-200">
                        @forelse ($review->strengths as $strength)
                            <li>{{ $strength }}</li>
                        @empty
                            <li class="list-none text-gray-400">None recorded.</li>
                        @endforelse
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-curation-issues>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Structured issues</h3>
                    <div class="mt-3 space-y-3">
                        @forelse ($review->issues as $issue)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-filament::badge :color="match ($issue['severity']) {
                                        'blocking', 'critical' => 'danger',
                                        'material', 'warning' => 'warning',
                                        default => 'info',
                                    }">{{ $issue['label'] }}</x-filament::badge>
                                    <span class="text-xs font-medium text-gray-500">{{ str($issue['severity'])->headline() }}</span>
                                    <code class="text-xs text-gray-500">{{ $issue['code'] }}</code>
                                </div>
                                @if (filled($issue['message']))
                                    <p class="mt-2 text-sm text-gray-800 dark:text-gray-200">{{ $issue['message'] }}</p>
                                @endif
                                @if ($issue['context'] !== [])
                                    <dl class="mt-2 grid grid-cols-1 gap-1 text-xs sm:grid-cols-2">
                                        @foreach ($issue['context'] as $context)
                                            <div><dt class="inline text-gray-500">{{ $context['label'] }}:</dt> <dd class="inline">{{ $context['value'] }}</dd></div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-gray-400">No issues recorded.</p>
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-2" data-curation-factor-breakdown>
                @foreach ([['Gift Score factors', $review->giftFactors], ['Catalog Value factors', $review->catalogFactors]] as [$heading, $factors])
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $heading }}</h3>
                        <div class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($factors as $factor)
                                <div class="flex items-center justify-between gap-3 py-2 text-sm">
                                    <div>
                                        <span>{{ $factor['label'] }}</span>
                                        @if ($factor['detail'])
                                            <span class="block text-xs text-gray-500">{{ $factor['detail'] }}</span>
                                        @endif
                                    </div>
                                    <span class="whitespace-nowrap font-semibold">{{ $factor['score'] }}</span>
                                </div>
                            @empty
                                <p class="py-2 text-sm text-gray-400">No factor data recorded.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-curation-peers>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Peer context</h3>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($review->peers as $peer)
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                            <p class="text-xs text-gray-500">{{ $peer['label'] }}</p>
                            <p class="mt-1 text-sm font-semibold">{{ $peer['count'] }} peers</p>
                            <p class="mt-1 text-xs text-gray-500">Gift IDs: {{ $peer['ids'] === [] ? '—' : implode(', ', $peer['ids']) }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-curation-fit-comparison>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Current vs audit fit</h3>
                <p class="mt-1 text-xs text-gray-500">Advisory audit findings only; no taxonomy is changed here.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="pb-2 pr-4 font-medium">Dimension</th>
                                <th class="pb-2 pr-4 font-medium">Current fit</th>
                                <th class="pb-2 pr-4 font-medium">Audit suggestions</th>
                                <th class="pb-2 font-medium">Differences</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($review->fitRows as $row)
                                <tr>
                                    <td class="py-2 pr-4 font-medium">{{ $row['dimension'] }}</td>
                                    <td class="py-2 pr-4">{{ $row['current'] === [] ? '—' : implode('; ', $row['current']) }}</td>
                                    <td class="py-2 pr-4">{{ $row['audit'] === [] ? '—' : implode('; ', $row['audit']) }}</td>
                                    <td class="py-2">
                                        @forelse ($row['differences'] as $difference)
                                            <div class="mb-1.5 last:mb-0">
                                                <x-filament::badge :color="match ($difference['severity']) {
                                                    'blocking' => 'danger',
                                                    'material' => 'warning',
                                                    default => 'info',
                                                }">{{ str($difference['severity'])->headline() }}</x-filament::badge>
                                                <span class="ml-1">{{ $difference['label'] }}</span>
                                                @if ($difference['forces_human_review'])
                                                    <span class="text-xs font-medium text-warning-600 dark:text-warning-400">Forces review</span>
                                                @endif
                                            </div>
                                        @empty
                                            No difference
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    @endif
</div>
