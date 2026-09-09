<?php

namespace App\Filament\Resources\Gifts\Pages;

use App\Actions\CuratedCatalog\ApproveCuratedTaxonomyProposalAction;
use App\Actions\CuratedCatalog\ReclassifyCuratedMerchantProductAction;
use App\Actions\CuratedCatalog\RejectCuratedTaxonomyProposalAction;
use App\Actions\Product\EvaluateAndPersistProductAutomationReadinessAction;
use App\Actions\Product\PublishProductAction;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Filament\Resources\Gifts\Concerns\AppliesGiftTaxonomyFormState;
use App\Filament\Resources\Gifts\GiftResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditGift extends EditRecord
{
    use AppliesGiftTaxonomyFormState;

    protected static string $resource = GiftResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillTaxonomyFormData($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        $taxonomyData = Arr::only($data, [
            'primary_category_id',
            'relationship_ids',
            'recipient_type_ids',
            'occasion_ids',
            'interest_ids',
            'profession_ids',
            'gift_type_ids',
        ]);

        $record->update($this->forgetTaxonomyFormData($data));

        return $this->persistTaxonomyFormData($record->fresh() ?? $record, $taxonomyData);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('approveClassification')
                    ->label('Approve Classification')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve this AI taxonomy proposal?')
                    ->modalDescription('The proposal will be validated against the current taxonomy and applied. The gift stays in draft.')
                    ->visible(fn (Product $record): bool => $this->canApprove($record))
                    ->action(function (Product $record): void {
                        try {
                            $product = app(ApproveCuratedTaxonomyProposalAction::class)->execute($record, auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Cannot approve classification')
                                ->body(implode(' ', Arr::flatten($exception->errors())))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Classification approved')
                            ->body('Taxonomy was applied. This gift remains a draft until you publish it.')
                            ->success()
                            ->send();

                        $this->record = $product;
                        $this->fillForm();
                    }),
                Action::make('rejectClassification')
                    ->label('Reject AI Proposal')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reject this AI proposal?')
                    ->modalDescription('The gift remains a draft. Provenance and affiliate data are kept. You can classify manually or reclassify later.')
                    ->visible(fn (Product $record): bool => $this->canReject($record))
                    ->action(function (Product $record): void {
                        try {
                            $product = app(RejectCuratedTaxonomyProposalAction::class)->execute($record);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Cannot reject proposal')
                                ->body(implode(' ', Arr::flatten($exception->errors())))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('AI proposal rejected')
                            ->success()
                            ->send();

                        $this->record = $product;
                        $this->fillForm();
                    }),
                Action::make('reclassify')
                    ->label('Reclassify')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->requiresConfirmation()
                    ->modalHeading('Reclassify this gift?')
                    ->modalDescription(fn (Product $record): string => $record->taxonomyClassificationIsHumanLocked()
                        ? 'Reclassification will generate a new AI proposal. Existing human-approved taxonomy will not be overwritten automatically.'
                        : 'This will generate a new AI taxonomy proposal using the current classification rules.')
                    ->visible(fn (Product $record): bool => ($record->taxonomy_classification_status ?? TaxonomyClassificationStatus::None)->allowsExplicitReclassify())
                    ->action(function (Product $record): void {
                        $result = app(ReclassifyCuratedMerchantProductAction::class)->execute($record);

                        Notification::make()
                            ->title($result->classified ? 'Reclassification finished' : 'Reclassification skipped')
                            ->body($this->reclassifyMessage($result->product, $result->classified, $result->reason))
                            ->success()
                            ->send();

                        $this->record = $result->product;
                        $this->fillForm();
                    }),
            ])
                ->label('Classification')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->button()
                ->dropdownPlacement('bottom-end'),
            Action::make('reevaluateReadiness')
                ->label('Re-evaluate readiness')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (Product $record): bool => $record->latestPromotedSourcingItem !== null)
                ->action(function (Product $record): void {
                    $item = $record->latestPromotedSourcingItem;

                    if ($item === null) {
                        return;
                    }

                    $item = app(EvaluateAndPersistProductAutomationReadinessAction::class)->execute($item);

                    Notification::make()
                        ->title('Readiness updated')
                        ->body(sprintf(
                            'Readiness is now %s with %d exception code(s).',
                            $item->readiness?->value ?? 'not set',
                            is_array($item->exception_codes) ? count($item->exception_codes) : 0,
                        ))
                        ->success()
                        ->send();

                    $this->record = $record->fresh(['latestPromotedSourcingItem.candidate']);
                }),
            Action::make('publish')
                ->label('Publish')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Publish this gift?')
                ->modalDescription('Publication requirements are validated by the existing publish action. Taxonomy classification is not changed to human-approved on publish.')
                ->visible(fn (Product $record): bool => $record->status !== ProductStatus::Published)
                ->action(function (Product $record): void {
                    try {
                        $result = app(PublishProductAction::class)->execute($record);

                        foreach ($result['warnings'] as $warning) {
                            Notification::make()
                                ->title($warning)
                                ->warning()
                                ->send();
                        }

                        Notification::make()
                            ->title('Gift published')
                            ->success()
                            ->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Cannot publish gift')
                            ->body(implode(' ', Arr::flatten($exception->errors())))
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshFormData([
                        'status',
                        'published_at',
                    ]);
                }),
            Action::make('archive')
                ->label('Archive')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (Product $record): bool => $record->status !== ProductStatus::Archived)
                ->action(function (Product $record): void {
                    $record->update([
                        'status' => ProductStatus::Archived,
                    ]);

                    Notification::make()
                        ->title('Gift archived')
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'status',
                    ]);
                }),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    private function canApprove(Product $record): bool
    {
        $status = $record->taxonomy_classification_status ?? TaxonomyClassificationStatus::None;

        if ($status === TaxonomyClassificationStatus::Review) {
            return is_array($record->taxonomy_classification_proposal)
                && $record->taxonomy_classification_proposal !== [];
        }

        return $status->isHumanLocked() && $record->taxonomyProposalIsPending();
    }

    private function canReject(Product $record): bool
    {
        $status = $record->taxonomy_classification_status ?? TaxonomyClassificationStatus::None;

        if (in_array($status, [
            TaxonomyClassificationStatus::Review,
            TaxonomyClassificationStatus::AiProposed,
        ], true)) {
            return true;
        }

        return $status->isHumanLocked() && $record->taxonomyProposalIsPending();
    }

    private function reclassifyMessage(Product $product, bool $classified, string $reason): string
    {
        if (! $classified) {
            return 'Classification was not run ('.$reason.').';
        }

        if ($product->taxonomyProposalIsPending()) {
            return 'A new AI proposal was stored for review. Applied human taxonomy was left unchanged.';
        }

        $status = $product->taxonomy_classification_status?->getLabel() ?? $reason;

        return 'Classification status is now '.$status.'.';
    }
}
