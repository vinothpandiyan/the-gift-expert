<?php

namespace App\Filament\Resources\CatalogCandidates\Pages;

use App\Actions\CatalogCandidate\ApproveCatalogCandidateAction;
use App\Actions\CatalogCandidate\RejectCatalogCandidateAction;
use App\Actions\CatalogCandidate\ReopenCatalogCandidateAction;
use App\Actions\CatalogCandidate\StartReviewCatalogCandidateAction;
use App\Actions\CatalogCandidate\UpdateCatalogCandidateAction;
use App\Actions\Product\EvaluateAndPersistProductAutomationReadinessAction;
use App\Enums\CatalogCandidateStatus;
use App\Filament\Resources\CatalogCandidates\CatalogCandidateResource;
use App\Models\CatalogCandidate;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditCatalogCandidate extends EditRecord
{
    protected static string $resource = CatalogCandidateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $allowSimilarTitle = (bool) ($data['allow_similar_title'] ?? false);
        unset($data['allow_similar_title'], $data['status']);

        /** @var CatalogCandidate $record */
        try {
            return app(UpdateCatalogCandidateAction::class)->execute($record, $data, $allowSimilarTitle);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError("data.{$field}", $message);
                }
            }

            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reevaluateReadiness')
                ->label('Re-evaluate readiness')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (CatalogCandidate $record): bool => $record->latestSourcingItem !== null)
                ->action(function (CatalogCandidate $record): void {
                    $item = $record->latestSourcingItem;

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

                    $this->record = $record->fresh(['latestSourcingItem.merchant', 'latestSourcingItem.product']);
                }),
            Action::make('startReview')
                ->label('Start review')
                ->icon(Heroicon::OutlinedEye)
                ->visible(fn (CatalogCandidate $record): bool => $record->status === CatalogCandidateStatus::Discovered)
                ->action(function (CatalogCandidate $record): void {
                    $this->runLifecycle(
                        fn (): CatalogCandidate => app(StartReviewCatalogCandidateAction::class)
                            ->execute($record, auth()->user()),
                        'Review started',
                        'Cannot start review',
                    );
                }),
            Action::make('approve')
                ->label('Approve')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Approve this catalog candidate?')
                ->modalDescription('Approval does not create a Gift or publish a Product.')
                ->visible(fn (CatalogCandidate $record): bool => $record->status === CatalogCandidateStatus::UnderReview)
                ->action(function (CatalogCandidate $record): void {
                    $this->runLifecycle(
                        fn (): CatalogCandidate => app(ApproveCatalogCandidateAction::class)
                            ->execute($record, auth()->user()),
                        'Catalog candidate approved',
                        'Cannot approve catalog candidate',
                    );
                }),
            Action::make('reject')
                ->label('Reject')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (CatalogCandidate $record): bool => in_array($record->status, [
                    CatalogCandidateStatus::Discovered,
                    CatalogCandidateStatus::UnderReview,
                    CatalogCandidateStatus::Approved,
                ], true))
                ->action(function (CatalogCandidate $record): void {
                    $this->runLifecycle(
                        fn (): CatalogCandidate => app(RejectCatalogCandidateAction::class)
                            ->execute($record, auth()->user()),
                        'Catalog candidate rejected',
                        'Cannot reject catalog candidate',
                    );
                }),
            Action::make('reopen')
                ->label('Reopen')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (CatalogCandidate $record): bool => $record->status === CatalogCandidateStatus::Rejected)
                ->action(function (CatalogCandidate $record): void {
                    $this->runLifecycle(
                        fn (): CatalogCandidate => app(ReopenCatalogCandidateAction::class)
                            ->execute($record, auth()->user()),
                        'Catalog candidate reopened',
                        'Cannot reopen catalog candidate',
                    );
                }),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * @param  callable(): CatalogCandidate  $callback
     */
    private function runLifecycle(callable $callback, string $successTitle, string $failureTitle): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($failureTitle)
                ->body(implode(' ', Arr::flatten($exception->errors())))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($successTitle)
            ->success()
            ->send();

        $this->refreshFormData([
            'status',
            'title',
        ]);

        $this->record = $this->getRecord()->fresh();
    }
}
