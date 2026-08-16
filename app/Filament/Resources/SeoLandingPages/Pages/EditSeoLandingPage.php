<?php

namespace App\Filament\Resources\SeoLandingPages\Pages;

use App\Actions\SeoLandingPage\PublishSeoLandingPageAction;
use App\Enums\SeoLandingPageStatus;
use App\Filament\Resources\SeoLandingPages\SeoLandingPageResource;
use App\Models\SeoLandingPage;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditSeoLandingPage extends EditRecord
{
    protected static string $resource = SeoLandingPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Publish')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Publish this SEO landing page?')
                ->modalDescription('Publication requires at least one filter dimension and a unique filter combination.')
                ->visible(fn (SeoLandingPage $record): bool => $record->status !== SeoLandingPageStatus::Published)
                ->action(function (SeoLandingPage $record): void {
                    try {
                        app(PublishSeoLandingPageAction::class)->execute($record);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Cannot publish SEO landing page')
                            ->body(implode(' ', Arr::flatten($exception->errors())))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('SEO landing page published')
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'published_at',
                    ]);
                }),
            Action::make('unpublish')
                ->label('Unpublish')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (SeoLandingPage $record): bool => $record->status === SeoLandingPageStatus::Published)
                ->action(function (SeoLandingPage $record): void {
                    $record->update([
                        'status' => SeoLandingPageStatus::Draft,
                    ]);

                    Notification::make()
                        ->title('SEO landing page unpublished')
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
