@php
    use App\CuratedCatalog\CuratedImageAcquisitionOutcome;

    $warningCodes = [
        'missing_relationships',
        'missing_occasions',
        'taxonomy_ids_rejected',
        'taxonomy_too_broad',
        'affiliate_not_ready',
        'missing_price',
        'missing_image',
        'missing_image_url',
        'unavailable',
        'availability_unavailable',
        'trashed_identity',
        'archived_product',
        'merchant_not_active',
        'commercial_conflicts',
        'needs_source_mapping',
        'malformed_source_list',
        CuratedImageAcquisitionOutcome::STATUS_MISSING_SOURCE,
        CuratedImageAcquisitionOutcome::STATUS_FAILED,
    ];

    $informationalCodes = [
        'missing_interests',
        'missing_recipient_types',
        'merged_occurrences',
        'classification_deferred',
        CuratedImageAcquisitionOutcome::STATUS_ACQUIRED,
        CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT,
    ];

    $warningLabels = [
        'missing_relationships' => 'No relationships',
        'missing_occasions' => 'No occasions',
        'missing_interests' => 'No interests',
        'missing_recipient_types' => 'No recipient types',
        'taxonomy_ids_rejected' => 'Rejected taxonomy IDs',
        'taxonomy_too_broad' => 'Broad taxonomy',
        'affiliate_not_ready' => 'Affiliate not ready',
        'missing_price' => 'Missing price',
        'missing_image' => 'Missing image',
        'missing_image_url' => 'Missing image',
        'unavailable' => 'Unavailable',
        'availability_unavailable' => 'Unavailable',
        'trashed_identity' => 'Trashed identity',
        'archived_product' => 'Archived gift',
        'merchant_not_active' => 'Merchant not active',
        'merged_occurrences' => 'Merged occurrences',
        'commercial_conflicts' => 'Commercial field conflicts',
        'needs_source_mapping' => 'Needs source mapping',
        'malformed_source_list' => 'Malformed source list',
        'classification_deferred' => 'Classification deferred',
        CuratedImageAcquisitionOutcome::STATUS_MISSING_SOURCE => 'Missing image source',
        CuratedImageAcquisitionOutcome::STATUS_FAILED => 'Image acquisition failed',
        CuratedImageAcquisitionOutcome::STATUS_ACQUIRED => 'Image acquired',
        CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT => 'Image already present',
    ];

    $imageStatusLabels = [
        CuratedImageAcquisitionOutcome::STATUS_ACQUIRED => 'Image acquired',
        CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT => 'Image present',
        CuratedImageAcquisitionOutcome::STATUS_MISSING_SOURCE => 'Image missing',
        CuratedImageAcquisitionOutcome::STATUS_FAILED => 'Image failed',
    ];

    $splitWarnings = static function (array $codes) use ($warningCodes, $informationalCodes): array {
        $warnings = [];
        $informational = [];

        foreach ($codes as $code) {
            if (in_array($code, $warningCodes, true)) {
                $warnings[] = $code;
            } elseif (in_array($code, $informationalCodes, true)) {
                $informational[] = $code;
            } else {
                $warnings[] = $code;
            }
        }

        return [$warnings, $informational];
    };

    $badgeClass = static function (string $tone): string {
        return match ($tone) {
            'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30',
            'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/30',
            'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30',
            'info' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-400/10 dark:text-info-300 dark:ring-info-400/30',
            default => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-300 dark:ring-gray-400/30',
        };
    };

    $imageBadgeTone = static function (?string $status): string {
        return match ($status) {
            CuratedImageAcquisitionOutcome::STATUS_ACQUIRED => 'success',
            CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT => 'info',
            CuratedImageAcquisitionOutcome::STATUS_MISSING_SOURCE,
            CuratedImageAcquisitionOutcome::STATUS_FAILED => 'warning',
            default => 'default',
        };
    };

    $classificationLinkLabel = static function (?string $status): string {
        return match ($status) {
            'review' => 'Review Required →',
            'failed' => 'Failed →',
            default => 'Edit Gift →',
        };
    };

    $classificationTone = static function (?string $status): string {
        return match ($status) {
            'review' => 'warning',
            'failed' => 'danger',
            'ai_accepted' => 'success',
            default => 'default',
        };
    };

    $summaryCards = $previewSummary ? [
        ['label' => 'Total', 'value' => $previewSummary['items_total'] ?? 0, 'accent' => '#6b7280'],
        ['label' => 'Unique', 'value' => $previewSummary['unique_products'] ?? $previewSummary['items_total'] ?? 0, 'accent' => '#0284c7'],
        ['label' => 'New', 'value' => $previewSummary['items_new'] ?? 0, 'accent' => '#0284c7'],
        ['label' => 'Existing', 'value' => $previewSummary['items_existing'] ?? 0, 'accent' => '#6b7280'],
        ['label' => 'Invalid', 'value' => $previewSummary['items_invalid'] ?? 0, 'accent' => '#dc2626'],
        ['label' => 'Ready to Sync', 'value' => $previewSummary['items_actionable'] ?? 0, 'accent' => '#16a34a'],
    ] : [];
@endphp

@if ($activeRunId && $syncProgress)
    @php
        $phase = $syncProgress['display_phase'] ?? 'processing';
        $heading = match ($phase) {
            'starting' => 'Starting sync...',
            'waiting_for_worker' => 'Waiting for queue worker',
            default => 'Sync in progress',
        };
        $percentage = min(max((int) ($syncProgress['percentage'] ?? 0), 0), 100);
    @endphp

    <div class="mt-6" wire:poll.5s="refreshSyncRun" data-sync-processing>
        <x-filament::section :heading="$heading">
            <div class="space-y-4" data-sync-progress-panel>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Run #{{ $syncProgress['run_id'] ?? $activeRunId }}
                </p>

                <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">
                        {{ $syncProgress['processed'] ?? 0 }} of {{ $syncProgress['total'] ?? $syncItemsTotal ?? 0 }} products processed
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ $percentage }}%
                    </p>
                </div>

                <div
                    class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="{{ $percentage }}"
                    data-sync-progress-bar
                >
                    <div
                        class="h-full rounded-full bg-primary-600 transition-all duration-300 dark:bg-primary-500"
                        style="width: {{ $percentage }}%"
                    ></div>
                </div>

                <div
                    class="grid grid-cols-2 gap-3 sm:grid-cols-5"
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(6.5rem, 1fr)); gap: 0.75rem;"
                    data-sync-progress-counts
                >
                    @foreach ([
                        ['label' => 'Created', 'value' => $syncProgress['created'] ?? 0],
                        ['label' => 'Updated', 'value' => $syncProgress['updated'] ?? 0],
                        ['label' => 'Skipped', 'value' => $syncProgress['skipped'] ?? 0],
                        ['label' => 'Failed', 'value' => $syncProgress['failed'] ?? 0],
                        ['label' => 'Remaining', 'value' => $syncProgress['remaining'] ?? 0],
                    ] as $count)
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/60">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $count['label'] }}
                            </p>
                            <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                                {{ $count['value'] }}
                            </p>
                        </div>
                    @endforeach
                </div>

                @if (($syncProgress['show_worker_hint'] ?? false) === true)
                    <div
                        class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200"
                        data-worker-hint
                    >
                        <p class="font-medium">Start a queue worker if one is not already running:</p>
                        <p class="mt-1 font-mono text-xs">./vendor/bin/sail artisan queue:work --timeout=3600 --tries=3</p>
                    </div>
                @elseif ($phase === 'processing')
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Processing continues in the background.
                    </p>
                @endif
            </div>
        </x-filament::section>
    </div>
@endif

@if ($previewSummary)
    <div class="mt-6 space-y-5">
        <x-filament::section heading="Preview summary">
            <div
                class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6"
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr)); gap: 0.75rem;"
                data-preview-summary
            >
                @foreach ($summaryCards as $card)
                    <div
                        class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900"
                        style="min-height: 5.75rem; padding: 1rem; border: 1px solid #e5e7eb; border-left: 3px solid {{ $card['accent'] }}; border-radius: 0.75rem; background: var(--gray-50, #fff);"
                        data-summary-card="{{ str($card['label'])->slug() }}"
                    >
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400" style="margin: 0; color: #6b7280; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase;">
                            {{ $card['label'] }}
                        </p>
                        <p class="mt-3 text-2xl font-semibold leading-none text-gray-950 dark:text-white" style="margin: 0.75rem 0 0; font-size: 1.5rem; font-weight: 650; line-height: 1;">
                            {{ $card['value'] }}
                        </p>
                    </div>
                @endforeach
            </div>

            <div
                class="mt-4 flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-relaxed text-gray-600 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-300"
                style="display: flex; align-items: flex-start; gap: 0.75rem; margin-top: 1rem; padding: 0.75rem 1rem; border: 1px solid #e5e7eb; border-radius: 0.5rem; background: #f9fafb;"
                role="note"
            >
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" style="width: 1.25rem; height: 1.25rem; flex: none; color: #9ca3af;" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0ZM9 9a1 1 0 000 2v3a1 1 0 102 0v-3a1 1 0 00-1-1H9Zm1-3a1 1 0 100 2 1 1 0 000-2Z" clip-rule="evenodd" />
                </svg>
                <p>
                    <span class="font-medium text-gray-700">Sync processes every actionable item in one run.</span>
                    New gifts receive AI enrichment and automatic image acquisition when a valid source image URL is present.
                </p>
            </div>
        </x-filament::section>

        @if ($previewRows)
            <x-filament::section heading="Preview items">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[64rem] border-separate border-spacing-0 text-sm">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                <th class="w-24 border-b border-gray-200 px-3 py-3 dark:border-gray-700">Image</th>
                                <th class="min-w-[18rem] border-b border-gray-200 px-4 py-3 dark:border-gray-700">Product</th>
                                <th class="border-b border-gray-200 px-4 py-3 whitespace-nowrap dark:border-gray-700">Price</th>
                                <th class="border-b border-gray-200 px-4 py-3 whitespace-nowrap dark:border-gray-700">Availability</th>
                                <th class="border-b border-gray-200 px-4 py-3 whitespace-nowrap dark:border-gray-700">Intake</th>
                                <th class="border-b border-gray-200 px-4 py-3 whitespace-nowrap dark:border-gray-700">Affiliate</th>
                                <th class="min-w-[10rem] border-b border-gray-200 px-4 py-3 dark:border-gray-700">Warnings</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($previewRows as $row)
                                @php
                                    [$rowWarnings, $rowInformational] = $splitWarnings($row['warnings'] ?? []);
                                @endphp
                                <tr class="align-top" data-preview-asin="{{ $row['asin'] ?? '' }}">
                                    <td class="border-b border-gray-100 px-3 py-4 dark:border-gray-800">
                                        <div class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800">
                                            @if (! empty($row['source_image_url']))
                                                <img
                                                    src="{{ $row['source_image_url'] }}"
                                                    alt=""
                                                    class="h-full w-full object-contain"
                                                    loading="lazy"
                                                    referrerpolicy="no-referrer"
                                                    onerror="this.replaceWith(this.nextElementSibling)"
                                                >
                                                <span class="hidden px-2 text-center text-[11px] text-gray-500">Image available</span>
                                            @elseif ($row['has_image'] ?? false)
                                                <span class="px-2 text-center text-[11px] text-gray-500">Image available</span>
                                            @else
                                                <span class="px-2 text-center text-[11px] text-gray-400">No image</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="border-b border-gray-100 px-4 py-4 dark:border-gray-800">
                                        <p class="mt-1 line-clamp-2 text-sm font-medium leading-snug text-gray-950 dark:text-white">{{ $row['title'] ?? '—' }}</p>
                                        <p class="mt-1 font-mono text-[11px] text-gray-500">{{ $row['asin'] ?? '—' }}</p>
                                        @if (! empty($row['source_list_names']))
                                            <p class="mt-1 text-[11px] text-gray-500">
                                                {{ count($row['source_list_names']) }} source list{{ count($row['source_list_names']) === 1 ? '' : 's' }}:
                                                {{ implode(', ', $row['source_list_names']) }}
                                            </p>
                                        @endif
                                        @if (! empty($row['relationship_hint_names']))
                                            <p class="mt-1 text-[11px] text-gray-500">
                                                Relationship hints: {{ implode(', ', $row['relationship_hint_names']) }}
                                            </p>
                                        @endif
                                    </td>
                                    <td class="border-b border-gray-100 px-4 py-4 font-medium whitespace-nowrap text-gray-900 dark:border-gray-800 dark:text-gray-100">
                                        {{ $row['price_display'] ?? '—' }}
                                    </td>
                                    <td class="border-b border-gray-100 px-4 py-4 whitespace-nowrap dark:border-gray-800">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $badgeClass($row['availability_tone'] ?? 'default') }}">
                                            {{ $row['availability_label'] ?? 'Unknown' }}
                                        </span>
                                    </td>
                                    <td class="border-b border-gray-100 px-4 py-4 whitespace-nowrap dark:border-gray-800">
                                        <div class="space-y-1">
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide ring-1 ring-inset {{ $badgeClass('default') }}">
                                                {{ $row['disposition_label'] ?? '—' }}
                                            </span>
                                            <div>
                                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $badgeClass($row['action_tone'] ?? 'default') }}">
                                                    {{ $row['action_label'] ?? '—' }}
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="border-b border-gray-100 px-4 py-4 whitespace-nowrap dark:border-gray-800">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $badgeClass(($row['affiliate_ready'] ?? false) ? 'success' : 'warning') }}">
                                            {{ ($row['affiliate_ready'] ?? false) ? 'Ready' : 'Not ready' }}
                                        </span>
                                    </td>
                                    <td class="border-b border-gray-100 px-4 py-4 dark:border-gray-800">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($rowWarnings as $code)
                                                <span class="inline-flex rounded-full bg-warning-50 px-2 py-0.5 text-[11px] font-medium text-warning-700 ring-1 ring-inset ring-warning-600/20">
                                                    {{ $warningLabels[$code] ?? $code }}
                                                </span>
                                            @endforeach
                                            @foreach ($rowInformational as $code)
                                                <span class="inline-flex rounded-full bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-gray-600 ring-1 ring-inset ring-gray-500/20">
                                                    {{ $warningLabels[$code] ?? $code }}
                                                </span>
                                            @endforeach
                                            @if ($rowWarnings === [] && $rowInformational === [])
                                                <span class="text-xs text-gray-400">—</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
@endif

@if ($commitResult)
    @php
        $processedItems = $commitResult['processed_items'] ?? [];
        $createdItems = array_values(array_filter($processedItems, fn (array $item): bool => ($item['outcome'] ?? '') === 'created'));
        $updatedItems = array_values(array_filter($processedItems, fn (array $item): bool => ($item['outcome'] ?? '') === 'updated'));
        $skippedItems = array_values(array_filter($processedItems, fn (array $item): bool => ($item['outcome'] ?? '') === 'skipped'));
        $failedItems = array_values(array_filter($processedItems, fn (array $item): bool => ($item['outcome'] ?? '') === 'failed'));
        $resultStatus = $commitResult['status'] ?? 'completed';
        $resultHeading = match ($resultStatus) {
            'failed' => 'Sync failed',
            'completed_with_errors' => 'Sync completed with issues',
            default => 'Sync result',
        };
        $resultTone = match ($resultStatus) {
            'failed' => 'danger',
            'completed_with_errors' => 'warning',
            default => 'default',
        };
        $totalProcessed = count($processedItems);
        $totalItems = (int) ($commitResult['items_total'] ?? $totalProcessed);
    @endphp

    <div class="mt-6 space-y-4" data-sync-result data-result-status="{{ $resultStatus }}">
        <x-filament::section :heading="$resultHeading">
            @if ($resultStatus === 'failed')
                <div
                    class="mb-4 rounded-lg border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200"
                    data-sync-failed-summary
                >
                    <p class="font-medium">
                        {{ $totalProcessed }} of {{ $totalItems }} products processed
                    </p>
                    <p class="mt-2 text-xs">
                        Created {{ $commitResult['items_created'] ?? 0 }},
                        failed {{ $commitResult['items_failed'] ?? 0 }},
                        remaining {{ max($totalItems - $totalProcessed, 0) }}
                    </p>
                    @if (! empty($commitResult['error']))
                        <p class="mt-2">{{ $commitResult['error'] }}</p>
                    @endif
                </div>
            @elseif ($resultStatus === 'completed_with_errors')
                <div
                    class="mb-4 rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200"
                    data-sync-warning-summary
                >
                    <p class="font-medium">
                        {{ $totalProcessed }} / {{ $totalProcessed }} processed
                    </p>
                </div>
            @endif
            <div
                class="grid grid-cols-2 gap-3 md:grid-cols-4"
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 0.75rem;"
                data-result-summary
            >
                @foreach ([
                    ['label' => 'Created', 'value' => $commitResult['items_created'] ?? 0, 'accent' => '#16a34a'],
                    ['label' => 'Updated', 'value' => $commitResult['items_updated'] ?? 0, 'accent' => '#0284c7'],
                    ['label' => 'Skipped', 'value' => $commitResult['items_skipped'] ?? 0, 'accent' => '#6b7280'],
                    ['label' => 'Failed', 'value' => $commitResult['items_failed'] ?? 0, 'accent' => '#dc2626'],
                ] as $card)
                    <div
                        class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900"
                        style="min-height: 5.75rem; padding: 1rem; border: 1px solid #e5e7eb; border-left: 3px solid {{ $card['accent'] }}; border-radius: 0.75rem; background: var(--gray-50, #fff);"
                        data-result-card="{{ str($card['label'])->slug() }}"
                    >
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500" style="margin: 0; color: #6b7280; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase;">
                            {{ $card['label'] }}
                        </p>
                        <p class="mt-3 text-2xl font-semibold leading-none text-gray-950 dark:text-white" style="margin: 0.75rem 0 0; font-size: 1.5rem; font-weight: 650; line-height: 1;">
                            {{ $card['value'] }}
                        </p>
                    </div>
                @endforeach
            </div>

            @if ($createdItems !== [])
                <div class="mt-6 space-y-3" data-outcome-group="created">
                    <h3 class="border-b border-gray-200 pb-2 text-sm font-semibold text-gray-950 dark:border-gray-700 dark:text-white">Created</h3>
                    <div class="space-y-2">
                        @foreach ($createdItems as $item)
                            @php
                                [$itemWarnings, $itemInformational] = $splitWarnings($item['warnings'] ?? []);
                            @endphp
                            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="space-y-1">
                                        <p class="font-medium text-gray-950 dark:text-white">{{ $item['product_name'] ?? $item['title'] ?? 'Created gift' }}</p>
                                        <p class="text-xs text-gray-500">ASIN: {{ $item['external_product_id'] ?? '—' }}</p>
                                        @if (! empty($item['source_list_names']))
                                            <p class="text-xs text-gray-500">
                                                {{ count($item['source_list_names']) }} source list{{ count($item['source_list_names']) === 1 ? '' : 's' }}:
                                                {{ implode(', ', $item['source_list_names']) }}
                                            </p>
                                        @endif
                                        <p class="text-xs text-gray-500">
                                            Affiliate: {{ ($item['affiliate_ready'] ?? false) ? 'Ready' : 'Not ready' }}
                                        </p>
                                        @if (! empty($item['image_status']))
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $badgeClass($imageBadgeTone($item['image_status'])) }}">
                                                {{ $imageStatusLabels[$item['image_status']] ?? $item['image_status'] }}
                                            </span>
                                        @endif
                                        @if (! empty($item['classification_label']))
                                            <span
                                                class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $badgeClass($classificationTone($item['classification_status'] ?? null)) }}"
                                                data-classification-status="{{ $item['classification_status'] }}"
                                            >
                                                {{ $item['classification_label'] }}
                                            </span>
                                        @endif
                                    </div>
                                    @if (! empty($item['product_id']))
                                        <a
                                            href="{{ \App\Filament\Resources\Gifts\GiftResource::getUrl('edit', ['record' => $item['product_id']]) }}"
                                            class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                                            data-edit-gift-link
                                        >
                                            {{ $classificationLinkLabel($item['classification_status'] ?? null) }}
                                        </a>
                                    @endif
                                </div>
                                @if ($itemWarnings !== [] || $itemInformational !== [])
                                    <div class="mt-3 flex flex-wrap items-center gap-1">
                                        @foreach ($itemWarnings as $code)
                                            <x-filament::badge color="warning">
                                                {{ $warningLabels[$code] ?? $code }}
                                            </x-filament::badge>
                                        @endforeach
                                        @foreach ($itemInformational as $code)
                                            <x-filament::badge color="gray">
                                                {{ $warningLabels[$code] ?? $code }}
                                            </x-filament::badge>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($updatedItems !== [])
                <div class="mt-6 space-y-3" data-outcome-group="updated">
                    <h3 class="border-b border-gray-200 pb-2 text-sm font-semibold text-gray-950 dark:border-gray-700 dark:text-white">Updated</h3>
                    <div class="space-y-2">
                        @foreach ($updatedItems as $item)
                            @php
                                [$itemWarnings, $itemInformational] = $splitWarnings($item['warnings'] ?? []);
                            @endphp
                            <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <span class="font-medium">{{ $item['product_name'] ?? $item['title'] ?? $item['external_product_id'] ?? 'Item' }}</span>
                                        <span class="text-gray-500"> — commercial refresh applied</span>
                                        @if (! empty($item['image_status']))
                                            <span class="ml-2 inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $badgeClass($imageBadgeTone($item['image_status'])) }}">
                                                {{ $imageStatusLabels[$item['image_status']] ?? $item['image_status'] }}
                                            </span>
                                        @endif
                                        @if (! empty($item['classification_label']))
                                            <span
                                                class="ml-2 inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $badgeClass($classificationTone($item['classification_status'] ?? null)) }}"
                                                data-classification-status="{{ $item['classification_status'] }}"
                                            >
                                                {{ $item['classification_label'] }}
                                            </span>
                                        @endif
                                    </div>
                                    @if (! empty($item['product_id']))
                                        <a
                                            href="{{ \App\Filament\Resources\Gifts\GiftResource::getUrl('edit', ['record' => $item['product_id']]) }}"
                                            class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                                            data-edit-gift-link
                                        >
                                            {{ $classificationLinkLabel($item['classification_status'] ?? null) }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($failedItems !== [])
                <div class="mt-6 space-y-3" data-outcome-group="failed">
                    <h3 class="border-b border-gray-200 pb-2 text-sm font-semibold text-gray-950 dark:border-gray-700 dark:text-white">Failed</h3>
                    <div class="space-y-2">
                        @foreach ($failedItems as $item)
                            <div class="rounded-lg border border-danger-200 bg-danger-50/50 p-3 text-sm dark:border-danger-500/30 dark:bg-danger-500/10">
                                <p class="font-medium text-danger-800 dark:text-danger-300">
                                    {{ $item['title'] ?? $item['external_product_id'] ?? 'Item' }}
                                </p>
                                <p class="mt-1 font-mono text-xs text-danger-700/80 dark:text-danger-300/80">
                                    ASIN: {{ $item['external_product_id'] ?? '—' }}
                                </p>
                                <p class="mt-1 text-danger-700 dark:text-danger-200">
                                    {{ $item['error'] ?? 'Sync failed' }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($skippedItems !== [] && count($skippedItems) <= 10)
                <div class="mt-6 space-y-3" data-outcome-group="skipped">
                    <h3 class="border-b border-gray-200 pb-2 text-sm font-semibold text-gray-950 dark:border-gray-700 dark:text-white">Skipped</h3>
                    <div class="space-y-2">
                        @foreach ($skippedItems as $item)
                            <div class="rounded-lg border border-gray-200 p-3 text-sm text-gray-600 dark:border-gray-700">
                                {{ $item['title'] ?? $item['external_product_id'] ?? 'Item' }}
                                @if (! empty($item['error']))
                                    <span class="text-gray-500"> — {{ $item['error'] }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-filament::section>
    </div>
@endif
