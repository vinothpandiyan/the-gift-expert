<?php

namespace App\Filament\Resources\HumanCuration\Pages;

use App\Actions\CatalogCuration\ApplyHumanCurationReclassificationAction;
use App\Actions\CatalogCuration\BuildHumanCurationReviewAction;
use App\Actions\CatalogCuration\CaptureProductTaxonomySnapshotAction;
use App\Actions\CatalogCuration\QueryHumanCurationQueueAction;
use App\Actions\CatalogCuration\RecordProductCurationDecisionAction;
use App\Actions\CatalogCuration\ResolveCurationReviewProgressAction;
use App\Actions\CatalogCuration\ResolveProductConceptPeersAction;
use App\CatalogCuration\HumanCurationReviewCase;
use App\CatalogCuration\HumanTaxonomyProposal;
use App\CatalogCuration\ProductConceptPeer;
use App\Enums\CatalogRole;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Enums\ProductCurationRemediationStatus;
use App\Filament\Resources\Gifts\GiftResource;
use App\Filament\Resources\HumanCuration\HumanCurationResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class ReviewHumanCuration extends ViewRecord
{
    protected static string $resource = HumanCurationResource::class;

    /**
     * @var list<int>
     */
    public array $selectedPeerIds = [];

    #[Url]
    public ?string $queueTab = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->queueTab ??= 'needs_review';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Review '.$this->getRecord()->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $progress = app(ResolveCurationReviewProgressAction::class)->execute();

        $featureShare = number_format($progress->featureShare * 100, 1);
        $warning = $progress->featureDensityWarning ? ' · FEATURE density is high' : '';

        return "Mandatory review: {$progress->mandatoryReview} · Decided: {$progress->decided} · Deferred: {$progress->deferred} · Remaining: {$progress->remaining} · FEATURE: {$progress->featureCount}/{$progress->keepFamilyCount} ({$featureShare}%){$warning}";
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $decision = $this->getRecord()->currentCurationDecision;
        $snapshot = app(CaptureProductTaxonomySnapshotAction::class)->execute($this->getRecord());

        return [
            'decision' => null,
            'reason_codes' => [],
            'reason_notes' => null,
            'catalog_role' => null,
            'confirm_supersede' => false,
            'expected_current_decision_id' => $decision?->id,
            'taxonomy_primary_category_id' => $snapshot->primaryCategoryId,
            'taxonomy_relationships_add' => [],
            'taxonomy_relationships_remove' => [],
            'taxonomy_occasions_add' => [],
            'taxonomy_occasions_remove' => [],
            'taxonomy_interests_add' => [],
            'taxonomy_interests_remove' => [],
            'taxonomy_gift_types_add' => [],
            'taxonomy_gift_types_remove' => [],
            'taxonomy_recipient_types_add' => [],
            'taxonomy_recipient_types_remove' => [],
            'taxonomy_professions_add' => [],
            'taxonomy_professions_remove' => [],
        ];
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->inlineLabel($this->hasInlineLabels())
            ->model($this->getRecord())
            ->operation('edit')
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.human-curation.review')
                    ->viewData(fn (): array => [
                        'case' => $this->reviewCase(),
                        'comparison' => $this->comparisonPeers(),
                        'queueUrl' => $this->queueUrl(),
                        'giftEditUrl' => GiftResource::getUrl('edit', ['record' => $this->getRecord()]),
                    ]),
                Form::make([EmbeddedSchema::make('form')])
                    ->id('human-curation-decision-form')
                    ->footer([
                        Actions::make([
                            Action::make('saveDecision')
                                ->label('Save decision')
                                ->icon(Heroicon::OutlinedCheck)
                                ->color('success')
                                ->requiresConfirmation()
                                ->modalHeading('Record this human curation decision?')
                                ->modalDescription(fn (): string => $this->decisionConsequence())
                                ->action(fn (): mixed => $this->saveDecision()),
                            Action::make('saveAndNext')
                                ->label('Save & Next')
                                ->icon(Heroicon::OutlinedArrowRight)
                                ->requiresConfirmation()
                                ->modalHeading('Record this human curation decision?')
                                ->modalDescription(fn (): string => $this->decisionConsequence())
                                ->action(fn (): mixed => $this->saveDecision('next')),
                            Action::make('saveAndPrevious')
                                ->label('Save & Previous')
                                ->icon(Heroicon::OutlinedArrowLeft)
                                ->requiresConfirmation()
                                ->modalHeading('Record this human curation decision?')
                                ->modalDescription(fn (): string => $this->decisionConsequence())
                                ->action(fn (): mixed => $this->saveDecision('previous')),
                            Action::make('executeRemediation')
                                ->label('Execute remediation')
                                ->icon(Heroicon::OutlinedPencilSquare)
                                ->color('warning')
                                ->visible(fn (): bool => HumanCurationResource::pendingReclassify($this->getRecord()))
                                ->requiresConfirmation()
                                ->modalHeading('Apply the approved taxonomy delta?')
                                ->modalDescription('This applies only the human-approved taxonomy changes, sets classification to human overridden, and does not publish, unpublish, or archive the gift. The accepted audit is left unchanged.')
                                ->action(fn (): mixed => $this->executeRemediation()),
                            Action::make('backToQueue')
                                ->label('Back to queue')
                                ->color('gray')
                                ->url($this->queueUrl()),
                        ]),
                    ]),
            ]);
    }

    public function saveDecision(?string $navigate = null): void
    {
        $data = $this->form->getState();
        $case = $this->reviewCase();

        if ($case === null) {
            Notification::make()
                ->title('No accepted audit is available for this gift.')
                ->danger()
                ->send();

            return;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            Notification::make()
                ->title('You must be signed in to record a curation decision.')
                ->danger()
                ->send();

            return;
        }

        $criteria = HumanCurationResource::criteriaFromTableState(
            null,
            $this->queueTab ?: 'needs_review',
            null,
            null,
        );
        $neighborId = in_array($navigate, ['next', 'previous'], true)
            ? app(QueryHumanCurationQueueAction::class)->clusterNeighbor(
                (int) $this->getRecord()->getKey(),
                $navigate,
                $case->conceptKey,
                $criteria,
            )
            : null;

        $decision = ProductCurationDecision::from((string) $data['decision']);
        $proposal = $decision === ProductCurationDecision::Reclassify
            ? HumanTaxonomyProposal::fromForm(
                $data,
                app(CaptureProductTaxonomySnapshotAction::class)->execute($this->getRecord())->primaryCategoryId,
            )
            : null;

        try {
            app(RecordProductCurationDecisionAction::class)->execute(
                product: $this->getRecord(),
                audit: $case->audit,
                decision: $decision,
                reasonCodes: ProductCurationDecisionReasonCode::fromValues($data['reason_codes'] ?? []),
                reasonNotes: $data['reason_notes'] ?? null,
                user: $user,
                catalogRole: filled($data['catalog_role'] ?? null)
                    ? CatalogRole::from((string) $data['catalog_role'])
                    : null,
                expectedCurrentDecisionId: filled($data['expected_current_decision_id'] ?? null)
                    ? (int) $data['expected_current_decision_id']
                    : null,
                confirmSupersede: (bool) ($data['confirm_supersede'] ?? false),
                taxonomyProposal: $proposal,
            );
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Decision was not recorded')
                ->body(implode(' ', Arr::flatten($exception->errors())))
                ->danger()
                ->send();

            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError(
                        str_starts_with((string) $key, 'data.') ? (string) $key : 'data.'.$key,
                        $message,
                    );
                }
            }

            return;
        }

        Notification::make()
            ->title('Human curation decision recorded')
            ->body(match ($decision) {
                ProductCurationDecision::Deactivate => 'The Product was archived. Taxonomy, offers, images, and the accepted audit were left unchanged.',
                ProductCurationDecision::Reclassify => 'RECLASSIFY is pending. Taxonomy will change only after Execute remediation succeeds.',
                default => 'The catalog was not mutated. Only the decision history changed.',
            })
            ->success()
            ->send();

        $this->redirect($this->nextUrl($navigate, $neighborId), navigate: true);
    }

    public function executeRemediation(): void
    {
        $decision = $this->getRecord()->currentCurationDecision;
        $user = auth()->user();

        if (! $user instanceof User) {
            Notification::make()
                ->title('You must be signed in to apply taxonomy remediations.')
                ->danger()
                ->send();

            return;
        }

        if ($decision?->decision !== ProductCurationDecision::Reclassify
            || $decision->remediation_status !== ProductCurationRemediationStatus::Pending) {
            Notification::make()
                ->title('No pending RECLASSIFY remediations to execute.')
                ->danger()
                ->send();

            return;
        }

        try {
            app(ApplyHumanCurationReclassificationAction::class)->execute($decision, $user);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Taxonomy remediations were not applied')
                ->body(implode(' ', Arr::flatten($exception->errors())))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Taxonomy remediations completed')
            ->body('Only the approved taxonomy delta was applied. Publication, archive state, and the accepted audit were left unchanged.')
            ->success()
            ->send();

        $this->redirect(HumanCurationResource::getUrl('review', [
            'record' => $this->getRecord(),
            ...$this->reviewParameters(),
        ]), navigate: true);
    }

    /**
     * @return list<ProductConceptPeer>
     */
    public function comparisonPeers(): array
    {
        $case = $this->reviewCase();

        if ($case === null) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $this->selectedPeerIds)));

        if (count($ids) < 2 || count($ids) > 4) {
            return [];
        }

        return app(ResolveProductConceptPeersAction::class)->forComparison(
            $case->run,
            [(int) $this->getRecord()->getKey(), ...$ids],
        );
    }

    public function reviewCase(): ?HumanCurationReviewCase
    {
        return app(BuildHumanCurationReviewAction::class)->execute($this->getRecord());
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewParameters(): array
    {
        return array_filter([
            'queueTab' => $this->queueTab ?: 'needs_review',
        ], fn (mixed $value): bool => filled($value));
    }

    public function queueUrl(): string
    {
        return HumanCurationResource::getUrl('index', array_filter([
            'tab' => $this->queueTab ?: 'needs_review',
        ], fn (mixed $value): bool => filled($value)));
    }

    private function decisionConsequence(): string
    {
        $decision = ProductCurationDecision::tryFrom((string) ($this->data['decision'] ?? ''));

        return $decision?->operatorConsequence() ?? ProductCurationDecision::defaultOperatorConsequence();
    }

    private function nextUrl(?string $navigate, ?int $neighborId = null): string
    {
        if (! in_array($navigate, ['next', 'previous'], true)) {
            return HumanCurationResource::getUrl('review', [
                'record' => $this->getRecord(),
                ...$this->reviewParameters(),
            ]);
        }

        if ($neighborId === null) {
            return $this->queueUrl();
        }

        return HumanCurationResource::getUrl('review', [
            'record' => $neighborId,
            ...$this->reviewParameters(),
        ]);
    }
}
