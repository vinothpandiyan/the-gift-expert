@php
    /** @var \App\CatalogCuration\HumanCurationReviewCase|null $case */
    $review = $case?->auditReview;
@endphp

<div class="space-y-6" data-human-curation-review>
    @if (! $case)
        <div class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center dark:border-gray-700">
            <p class="text-sm font-medium text-gray-950 dark:text-white">No accepted curation audit</p>
            <p class="mt-1 text-sm text-gray-500">This gift is not part of the latest accepted full-catalog audit baseline.</p>
        </div>
    @else
        <section class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Source audit</p>
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $case->run->id }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-filament::badge :color="$case->priority->getColor()">{{ $case->priority->getLabel() }}</x-filament::badge>
                @if ($case->qualitySubgroup)
                    <x-filament::badge :color="$case->qualitySubgroup->getColor()">{{ $case->qualitySubgroup->getLabel() }}</x-filament::badge>
                @endif
                <x-filament::badge :color="$case->audit->requires_human_review ? 'warning' : 'success'">
                    {{ $case->audit->requires_human_review ? 'Human review required' : 'Advisory only' }}
                </x-filament::badge>
                <a href="{{ $giftEditUrl }}" class="text-sm font-medium text-primary-600">Open gift editor</a>
                <a href="{{ $queueUrl }}" class="text-sm font-medium text-gray-600">Return to queue</a>
            </div>
        </section>

        @if ($case->integrityDiagnosis)
            <section class="rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950/40" data-p0-diagnosis>
                <h3 class="text-sm font-semibold text-rose-950 dark:text-rose-100">P0 integrity diagnosis</h3>
                <p class="mt-2 text-sm font-medium text-rose-950 dark:text-rose-100">{{ $case->integrityDiagnosis->diagnosis->getLabel() }}</p>
                <p class="mt-1 text-sm text-rose-900 dark:text-rose-100">{{ $case->integrityDiagnosis->summary }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-rose-900 dark:text-rose-100">
                    @foreach ($case->integrityDiagnosis->signals as $signal)
                        <li>{{ $signal }}</li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-rose-800 dark:text-rose-200">This diagnosis is decision support only. It does not record a human decision or mutate the Product.</p>
            </section>
        @endif

        @if ($case->priority === \App\Enums\ProductCurationPriority::P1)
            <section class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-900 dark:bg-indigo-950/40" data-cluster-question>
                <h3 class="text-sm font-semibold text-indigo-950 dark:text-indigo-100">Cluster curation question</h3>
                <p class="mt-2 text-sm text-indigo-950 dark:text-indigo-100">How many Products in this concept genuinely deserve to remain in The Gift Expert catalog, and what distinct role does each retained Product serve?</p>
                <p class="mt-2 text-xs text-indigo-800 dark:text-indigo-200">Do not keep interchangeable Products solely to create choice. Shared concept is not automatically a duplicate.</p>
            </section>
        @endif

        @if ($case->priority === \App\Enums\ProductCurationPriority::P2 && $case->taxonomyReview)
            <section class="rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-900 dark:bg-sky-950/40" data-p2-taxonomy-review>
                <h3 class="text-sm font-semibold text-sky-950 dark:text-sky-100">P2 taxonomy review</h3>
                <p class="mt-2 text-sm text-sky-950 dark:text-sky-100">Does the current taxonomy cause this gift to appear on a targeted landing page where a reasonable shopper would consider it misleading or materially irrelevant?</p>
                <p class="mt-2 text-xs text-sky-800 dark:text-sky-200">Preserve valid assignments. Correct genuinely misleading taxonomy. Do not add AI suggestions merely because they are plausible.</p>
                @if ($case->currentDecision?->decision === \App\Enums\ProductCurationDecision::Reclassify)
                    <p class="mt-3 text-xs font-medium text-sky-900 dark:text-sky-100" data-p2-remediation-state>
                        Remediation: {{ $case->currentDecision->remediation_status?->getLabel() ?? 'Unknown' }}
                    </p>
                @endif
            </section>
        @endif

        @if ($case->priority === \App\Enums\ProductCurationPriority::P3)
            <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40" data-p3-quality-review>
                <h3 class="text-sm font-semibold text-emerald-950 dark:text-emerald-100">P3 quality review</h3>
                <p class="mt-2 text-sm text-emerald-950 dark:text-emerald-100">If you were recommending gifts to a real shopper, would you confidently keep this Product in The Gift Expert, and what role does it serve that justifies its presence?</p>
                <p class="mt-2 text-xs text-emerald-800 dark:text-emerald-200">This is a merchandising-quality review, not a score-cleanup exercise. Low Gift Score or Catalog Value is evidence, not an automatic removal.</p>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-emerald-950 dark:text-emerald-100">
                    <li>Gift desirability — would the intended recipient genuinely appreciate it?</li>
                    <li>Thoughtfulness — does it feel like a gift rather than merely a purchase?</li>
                    <li>Differentiation — why this Product instead of a generic equivalent?</li>
                    <li>Value — does the gifting experience justify the price?</li>
                    <li>Catalog role — why does The Gift Expert need this Product?</li>
                    <li>Confidence — would we recommend it from the available evidence?</li>
                </ol>
                @if ($case->qualitySubgroup === \App\Enums\P3QualitySubgroup::LowCatalogValue)
                    <p class="mt-3 text-xs font-medium text-emerald-900 dark:text-emerald-100">Catalog-purpose check: budget option, premium option, specific Interest, GiftIntent, occasion, recipient niche, unique format, personalization, experience, or digital/instant. If none applies, that is strong evidence for REMOVE CANDIDATE or DEACTIVATE.</p>
                @endif
                @if ($case->qualitySubgroup === \App\Enums\P3QualitySubgroup::WeakEvidence)
                    <p class="mt-3 text-xs font-medium text-emerald-900 dark:text-emerald-100">Do not confuse a poor Product with insufficient evidence. A strong gift with incomplete data may warrant DEFER or KEEP with an evidence note.</p>
                @endif
                @if ($case->qualitySubgroup === \App\Enums\P3QualitySubgroup::LowConfidence)
                    <p class="mt-3 text-xs font-medium text-emerald-900 dark:text-emerald-100">Do not automatically trust evaluator conclusions. Weight title, image, price, features, taxonomy, peers, and actual gifting usefulness. If still uncertain, DEFER.</p>
                @endif
            </section>
        @endif

        <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/40" data-why-review>
            <h3 class="text-sm font-semibold text-amber-950 dark:text-amber-100">Why this needs review</h3>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-950 dark:text-amber-100">
                @forelse ($case->reviewReasons as $reason)
                    <li>{{ $reason }}</li>
                @empty
                    <li class="list-none text-amber-800">No structured review trigger is recorded.</li>
                @endforelse
            </ul>
        </section>

        <section class="grid gap-4 lg:grid-cols-2" data-product-summary>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex gap-4">
                    @if ($case->productSummary['image_url'])
                        <img src="{{ $case->productSummary['image_url'] }}" alt="" class="h-24 w-24 rounded-lg object-cover">
                    @endif
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $case->productSummary['title'] }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $case->productSummary['price'] ?? 'No price' }} · {{ $case->productSummary['merchant'] ?? 'No merchant' }}</p>
                        <p class="mt-1 text-xs text-gray-500">Status: {{ $case->productSummary['status'] ?? '—' }} · Affiliate: {{ $case->productSummary['affiliate'] ?? '—' }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            Current offer:
                            {{ $case->productSummary['availability'] ? str($case->productSummary['availability'])->replace('_', ' ')->headline() : 'Unknown availability' }}
                            · Observed {{ $case->productSummary['last_seen_at'] ?? '—' }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500">Provenance: {{ $case->productSummary['provenance'] ? implode(', ', $case->productSummary['provenance']) : 'None' }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            GiftIntent: {{ implode(', ', $review?->intents ?? []) ?: '—' }}
                            · Concept: {{ $case->conceptLabel ?? '—' }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500">
                            Strongest:
                            @foreach ($case->strongestFits as $fit)
                                {{ $fit['label'] }} {{ $fit['name'] !== '' ? $fit['name'] : '—' }}@if ($fit['strength'] !== '') ({{ $fit['strength'] }})@endif{{ ! $loop->last ? ' · ' : '' }}
                            @endforeach
                        </p>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-human-vs-audit>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Human authority vs evaluator</h3>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500">Current human decision</dt>
                        <dd class="font-semibold">{{ $case->currentDecision?->decision?->getLabel() ?? 'None' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Latest evaluator recommendation</dt>
                        <dd class="font-semibold">{{ $case->latestAudit->recommendation?->value ? str($case->latestAudit->recommendation->value)->replace('_', ' ')->headline() : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Decision date</dt>
                        <dd>{{ $case->currentDecision?->decided_at?->toDateTimeString() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Reviewer</dt>
                        <dd>{{ $case->currentDecision?->decidedBy?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Source audit</dt>
                        <dd class="break-all">{{ $case->currentDecision?->source_audit_run_id ?? $case->run->id }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Reason</dt>
                        <dd>{{ $case->currentDecision ? implode(', ', array_map(fn ($code) => $code->getLabel(), $case->currentDecision->reasonCodeEnums())) : '—' }}</dd>
                    </div>
                </dl>
                @if ($case->recommendationDisagrees)
                    <p class="mt-3 text-xs font-medium text-amber-700">The latest evaluator recommendation differs from the current human decision. They are not automatically reconciled.</p>
                @endif
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-score-summary>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Scores</h3>
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6 text-sm">
                @foreach ([
                    ['Gift Score', $case->audit->gift_score],
                    ['Catalog Value', $case->audit->catalog_value_score],
                    ['AI confidence', $case->audit->ai_confidence?->value],
                    ['Vendor confidence', data_get($case->audit->gift_score_components, 'product_vendor_confidence.score')],
                    ['Audit recommendation', $case->audit->recommendation?->value],
                    ['Human-review required', $case->audit->requires_human_review ? 'Yes' : 'No'],
                ] as [$label, $value])
                    <div>
                        <dt class="text-xs text-gray-500">{{ $label }}</dt>
                        <dd class="font-semibold">{{ is_string($value) ? str($value)->replace('_', ' ')->headline() : ($value ?? '—') }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="grid gap-4 lg:grid-cols-2" data-score-breakdown>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Gift Score breakdown</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($case->giftFactors as $factor)
                        <li><span class="font-medium">{{ $factor['label'] }}:</span> {{ $factor['score'] }}@if ($factor['detail']) <span class="text-gray-500">{{ $factor['detail'] }}</span>@endif</li>
                    @endforeach
                </ul>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Catalog Value breakdown</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($case->catalogFactors as $factor)
                        <li><span class="font-medium">{{ $factor['label'] }}:</span> {{ $factor['score'] }}</li>
                    @endforeach
                </ul>
                @if (is_array($case->relativeCoverage))
                    <p class="mt-4 text-xs font-medium uppercase tracking-wide text-gray-500">Relative coverage (scoring context v3)</p>
                    <dl class="mt-2 grid grid-cols-2 gap-2 text-xs text-gray-600">
                        <div>Concept peers: {{ $case->relativeCoverage['concept_peer_count'] ?? '—' }}</div>
                        <div>Exact concept: {{ $case->relativeCoverage['concept_exact_component'] ?? '—' }}</div>
                        <div>Context scarcity: {{ $case->relativeCoverage['context_scarcity'] ?? '—' }}</div>
                        <div>Shared scarcity scale: {{ $case->relativeCoverage['shared_scarcity_scale'] ?? '—' }}</div>
                    </dl>
                @endif
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Explanation</h3>
                <p class="mt-3 text-sm">{{ $review?->whyThisGift ?? 'No rationale recorded.' }}</p>
                <p class="mt-4 text-xs uppercase tracking-wide text-gray-500">Strengths</p>
                <ul class="mt-2 list-disc pl-5 text-sm">
                    @forelse ($review?->strengths ?? [] as $strength)
                        <li>{{ $strength }}</li>
                    @empty
                        <li class="list-none text-gray-400">None recorded.</li>
                    @endforelse
                </ul>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Classification</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div><dt class="text-xs text-gray-500">Primary category</dt><dd>{{ $case->classification['primary_category'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Relationships</dt><dd>{{ implode(', ', $case->classification['relationships'] ?? []) ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Occasions</dt><dd>{{ implode(', ', $case->classification['occasions'] ?? []) ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Interests</dt><dd>{{ implode(', ', $case->classification['interests'] ?? []) ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">GiftTypes</dt><dd>{{ implode(', ', $case->classification['gift_types'] ?? []) ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">RecipientTypes</dt><dd>{{ implode(', ', $case->classification['recipient_types'] ?? []) ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Professions</dt><dd>{{ implode(', ', $case->classification['professions'] ?? []) ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Classification lifecycle</dt><dd>{{ $case->classification['classification_status'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">GiftIntents</dt><dd>{{ implode(', ', $review?->intents ?? []) ?: '—' }}</dd></div>
                </dl>
            </div>
        </section>

        @if ($case->taxonomyReview)
            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-current-taxonomy>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Current taxonomy & audit fit</h3>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    @foreach ($case->taxonomyReview->currentAssignments as $dimension => $assignments)
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">{{ str($dimension)->replace('_', ' ')->headline() }}</p>
                            <ul class="mt-1 space-y-1 text-sm">
                                @forelse ($assignments as $assignment)
                                    <li>
                                        {{ $assignment['name'] }}
                                        @if ($assignment['strength'])
                                            · {{ str($assignment['strength'])->headline() }}
                                        @endif
                                        @if ($assignment['misleading'] === true)
                                            · Misleading on targeted page
                                        @endif
                                        @if ($assignment['reason'])
                                            <span class="text-gray-500">— {{ $assignment['reason'] }}</span>
                                        @endif
                                    </li>
                                @empty
                                    <li class="text-gray-400">None assigned.</li>
                                @endforelse
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-material-findings>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Material findings</h3>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                    @forelse ($case->taxonomyReview->materialFindings as $finding)
                        <li>
                            {{ str($finding['dimension'])->replace('_', ' ')->headline() }}
                            @if ($finding['name'])
                                “{{ $finding['name'] }}”
                            @endif
                            · {{ str($finding['severity'])->headline() }}
                            @if ($finding['reason'])
                                — {{ $finding['reason'] }}
                            @endif
                        </li>
                    @empty
                        <li class="list-none text-gray-400">No material taxonomy finding is recorded.</li>
                    @endforelse
                </ul>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-suggested-taxonomy>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Suggested taxonomy</h3>
                <p class="mt-1 text-xs text-gray-500">Advisory only. Do not treat these as approved changes.</p>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    @foreach ($case->taxonomyReview->suggestedAdditions as $dimension => $suggestions)
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">{{ str($dimension)->replace('_', ' ')->headline() }}</p>
                            <ul class="mt-1 space-y-1 text-sm">
                                @forelse ($suggestions as $suggestion)
                                    <li>
                                        {{ $suggestion['name'] }}
                                        @if ($suggestion['strength'])
                                            · {{ str($suggestion['strength'])->headline() }}
                                        @endif
                                        @if ($suggestion['reason'])
                                            <span class="text-gray-500">— {{ $suggestion['reason'] }}</span>
                                        @endif
                                    </li>
                                @empty
                                    <li class="text-gray-400">No audit-only suggestion.</li>
                                @endforelse
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-authoritative-applicability>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Authoritative applicability</h3>
                @if ($case->taxonomyReview->hasAuthoritativeConflict)
                    <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-rose-700 dark:text-rose-200">
                        @foreach ($case->taxonomyReview->applicabilityConflicts as $conflict)
                            <li>{{ $conflict }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-3 text-sm text-gray-600">No configured applicability conflict on the current assignments.</p>
                @endif
            </section>
        @endif

        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-taxonomy-findings>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Taxonomy findings</h3>
            <div class="mt-3 space-y-3">
                @foreach ($review?->fitRows ?? [] as $row)
                    <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                        <p class="font-medium">{{ $row['dimension'] }}</p>
                        <p class="mt-1 text-xs text-gray-500">Current: {{ implode('; ', $row['current']) ?: '—' }}</p>
                        <p class="text-xs text-gray-500">Evaluator: {{ implode('; ', $row['audit']) ?: '—' }}</p>
                        @foreach ($row['differences'] as $difference)
                            <p class="mt-1">{{ $difference['label'] }} · {{ str($difference['severity'])->headline() }}@if ($difference['forces_human_review']) · Forces review @endif</p>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-evidence>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Evidence</h3>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-gray-500">Audit snapshot price</dt><dd>{{ $case->evidence['price'] ?? '—' }} {{ $case->evidence['currency'] ?? '' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Audit offer freshness</dt><dd>{{ $case->evidence['offer_freshness'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Primary image</dt><dd>{{ $case->evidence['has_primary_image'] ? 'Yes' : 'No' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Provenance</dt><dd>{{ $case->evidence['provenance_count'] }} source(s)</dd></div>
                <div class="col-span-2"><dt class="text-xs text-gray-500">Missing evidence</dt><dd>{{ $case->evidence['missing'] ? implode(', ', $case->evidence['missing']) : 'None flagged' }}</dd></div>
            </dl>
            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900 dark:bg-emerald-950/40" data-current-merchant-observation>
                <h4 class="text-sm font-semibold text-emerald-950 dark:text-emerald-100">Current merchant observation</h4>
                <p class="mt-1 text-xs text-emerald-800 dark:text-emerald-200">Live Product / offer fields. This does not rewrite the frozen audit snapshot.</p>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs text-emerald-800 dark:text-emerald-200">Current price</dt><dd>{{ $case->evidence['current']['price'] ?? '—' }} {{ $case->evidence['current']['currency'] ?? '' }}</dd></div>
                    <div><dt class="text-xs text-emerald-800 dark:text-emerald-200">Availability</dt><dd>{{ $case->evidence['current']['availability'] ? str($case->evidence['current']['availability'])->replace('_', ' ')->headline() : '—' }}</dd></div>
                    <div><dt class="text-xs text-emerald-800 dark:text-emerald-200">Observed</dt><dd>{{ $case->evidence['current']['last_seen_at'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-emerald-800 dark:text-emerald-200">Verified</dt><dd>{{ $case->evidence['current']['last_verified_at'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-emerald-800 dark:text-emerald-200">Merchant / identity</dt><dd>{{ $case->evidence['current']['merchant'] ?? '—' }} · {{ $case->evidence['current']['external_product_id'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-emerald-800 dark:text-emerald-200">Exact listing present</dt><dd>{{ ($case->evidence['current']['exact_identity_present'] ?? false) ? 'Yes' : 'No' }}</dd></div>
                    <div class="col-span-2"><dt class="text-xs text-emerald-800 dark:text-emerald-200">Source-list last seen</dt><dd>{{ $case->evidence['current']['provenance_last_seen_at'] ?? '—' }}</dd></div>
                </dl>
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-concept-peers>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Concept peer comparison</h3>
                @if ($case->conceptProgress)
                    <p class="text-sm text-gray-600">{{ $case->conceptProgress->label }} · {{ $case->conceptProgress->products }} Products · {{ $case->conceptProgress->reviewed }} reviewed · {{ $case->conceptProgress->remaining }} remaining</p>
                @endif
            </div>
            <p class="mt-2 text-xs text-gray-500">Concept: {{ $case->conceptLabel ?? '—' }}</p>
            @if ($case->currentPeer)
                <p class="mt-2 text-sm">Current: Product {{ $case->currentPeer->productId }} · Gift {{ $case->currentPeer->giftScore ?? '—' }} · Catalog Value {{ $case->currentPeer->catalogValue ?? '—' }}</p>
            @endif
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                @forelse ($case->peers as $peer)
                    <label class="flex gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <input type="checkbox" wire:model.live="selectedPeerIds" value="{{ $peer->productId }}" class="mt-1">
                        <div class="min-w-0">
                            @if ($peer->imageUrl)
                                <img src="{{ $peer->imageUrl }}" alt="" class="mb-2 h-16 w-16 rounded object-cover">
                            @endif
                            <p class="text-sm font-medium">{{ $peer->title }}</p>
                            <p class="text-xs text-gray-500">{{ $peer->price ?? 'No price' }} · Gift {{ $peer->giftScore ?? '—' }} · CV {{ $peer->catalogValue ?? '—' }} · Diff {{ $peer->differentiation ?? '—' }}</p>
                            <p class="text-xs text-gray-500">{{ $peer->budgetBand ?? 'No band' }} · {{ implode(', ', $peer->giftIntents) ?: 'No intents' }}</p>
                            <p class="text-xs text-gray-500">{{ implode(', ', $peer->taxonomyContext) ?: 'No taxonomy context' }}</p>
                            <p class="text-xs text-gray-500">Human decision: {{ $peer->humanDecision?->getLabel() ?? 'None' }}</p>
                            <a href="{{ \App\Filament\Resources\HumanCuration\HumanCurationResource::getUrl('review', ['record' => $peer->productId, 'queueTab' => $this->queueTab]) }}" class="text-xs font-medium text-primary-600">Open peer</a>
                        </div>
                    </label>
                @empty
                    <p class="text-sm text-gray-500">No concept peers in the accepted audit.</p>
                @endforelse
            </div>
            @if ($case->hiddenPeerCount > 0)
                <p class="mt-2 text-xs text-gray-500">{{ $case->hiddenPeerCount }} additional peer(s) are hidden. Use the Concept filter on the queue to view all.</p>
            @endif
        </section>

        @if ($comparison !== [])
            <section class="overflow-x-auto rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-side-by-side>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Side-by-side comparison</h3>
                <table class="mt-3 min-w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th class="py-2 pr-3">Field</th>
                            @foreach ($comparison as $peer)
                                <th class="py-2 pr-3">{{ $peer->title }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([
                            'Product' => fn ($peer) => 'Product '.$peer->productId,
                            'Price' => fn ($peer) => $peer->price ?? '—',
                            'Gift Score' => fn ($peer) => $peer->giftScore ?? '—',
                            'Catalog Value' => fn ($peer) => $peer->catalogValue ?? '—',
                            'GiftIntent' => fn ($peer) => implode(', ', $peer->giftIntents) ?: '—',
                            'Taxonomy' => fn ($peer) => implode(', ', $peer->taxonomyContext) ?: '—',
                            'Differentiation' => fn ($peer) => $peer->differentiation ?? '—',
                            'Human decision' => fn ($peer) => $peer->humanDecision?->getLabel() ?? 'None',
                            'Role' => fn ($peer) => $peer->humanRole?->value ? str($peer->humanRole->value)->replace('_', ' ')->headline() : '—',
                        ] as $label => $resolver)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <th class="py-2 pr-3 text-xs text-gray-500">{{ $label }}</th>
                                @foreach ($comparison as $peer)
                                    <td class="py-2 pr-3">{{ $resolver($peer) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-decision-history>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Decision history</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($case->history as $decision)
                    <li>
                        {{ $decision->decided_at?->toDateTimeString() }}
                        · {{ $decision->decision?->getLabel() }}
                        · {{ $decision->decidedBy?->name ?? 'Unknown reviewer' }}
                        · {{ $decision->isCurrent() ? 'Current' : 'Superseded' }}
                        · Audit {{ $decision->source_audit_run_id }}
                    </li>
                @empty
                    <li class="text-gray-500">No human curation decision has been recorded yet.</li>
                @endforelse
            </ul>
        </section>
    @endif
</div>
