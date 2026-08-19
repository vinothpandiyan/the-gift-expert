<?php

namespace App\Filament\Resources\Gifts\Pages;

use App\Actions\Product\EvaluateAndPersistProductAutomationReadinessAction;
use App\Actions\Product\PublishProductAction;
use App\Enums\ProductStatus;
use App\Filament\Resources\Gifts\GiftResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditGift extends EditRecord
{
    protected static string $resource = GiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
                ->modalDescription('Publication requirements are validated by the existing publish action.')
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
}
