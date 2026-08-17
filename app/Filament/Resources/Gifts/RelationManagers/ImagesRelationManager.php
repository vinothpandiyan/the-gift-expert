<?php

namespace App\Filament\Resources\Gifts\RelationManagers;

use App\Actions\ProductImage\DeleteProductImageAction;
use App\Actions\ProductImage\SetPrimaryProductImageAction;
use App\Actions\ProductImage\StoreProductImageAction;
use App\Models\Product;
use App\Models\ProductImage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use SplFileInfo;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Images';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('alt_text')
                    ->maxLength(255),
                Toggle::make('is_primary')
                    ->label('Primary image')
                    ->default(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('path')
            ->columns([
                ImageColumn::make('path')
                    ->label('Image')
                    ->disk(fn (ProductImage $record): string => $record->disk ?: (string) config('media.product_images.disk'))
                    ->square()
                    ->imageHeight(64),
                TextColumn::make('alt_text')
                    ->toggleable(),
                TextColumn::make('sort_order')
                    ->sortable(),
                IconColumn::make('is_primary')
                    ->boolean()
                    ->label('Primary'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Upload images')
                    ->modalHeading('Upload images')
                    ->createAnother(false)
                    ->schema($this->uploadSchema())
                    ->using(function (array $data): ProductImage {
                        /** @var Product $product */
                        $product = $this->getOwnerRecord();

                        try {
                            $images = app(StoreProductImageAction::class)->execute(
                                product: $product,
                                sources: $this->sourceFiles($data['uploads'] ?? []),
                                altText: $data['alt_text'] ?? null,
                                preferPrimary: (bool) ($data['is_primary'] ?? false),
                            );
                        } catch (ValidationException $exception) {
                            throw ValidationException::withMessages([
                                'uploads' => Arr::flatten($exception->errors()),
                            ]);
                        }

                        $stored = $images->last();

                        if (! $stored instanceof ProductImage) {
                            throw ValidationException::withMessages([
                                'uploads' => ['The images could not be stored.'],
                            ]);
                        }

                        return $stored;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (ProductImage $record): void {
                        if ($record->is_primary) {
                            app(SetPrimaryProductImageAction::class)->execute($record);

                            return;
                        }

                        app(SetPrimaryProductImageAction::class)->ensureForProduct($record->product);
                    }),
                DeleteAction::make()
                    ->using(function (ProductImage $record): void {
                        app(DeleteProductImageAction::class)->execute($record);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function (Collection $records): void {
                            $records->each(function (ProductImage $record): void {
                                app(DeleteProductImageAction::class)->execute($record);
                            });
                        }),
                ]),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order');
    }

    /**
     * @return list<FileUpload|TextInput|Toggle>
     */
    private function uploadSchema(): array
    {
        $aspectRatio = (string) config('media.product_images.aspect_ratio');

        return [
            FileUpload::make('uploads')
                ->label('Images')
                ->multiple()
                ->image()
                ->acceptedFileTypes(config('media.product_images.allowed_mime_types'))
                ->maxSize((int) config('media.product_images.max_upload_kilobytes'))
                ->maxFiles((int) config('media.product_images.max_files_per_upload'))
                ->panelLayout('grid')
                ->imageEditor()
                ->imageEditorMode(1)
                ->imageAspectRatio($aspectRatio)
                ->imageEditorAspectRatioOptions([$aspectRatio])
                ->storeFiles(false)
                ->previewable()
                ->required(),
            TextInput::make('alt_text')
                ->maxLength(255),
            Toggle::make('is_primary')
                ->label('Set the first uploaded image as primary')
                ->default(false),
        ];
    }

    /**
     * @return list<string|SplFileInfo>
     */
    private function sourceFiles(mixed $uploads): array
    {
        $files = [];

        foreach (Arr::wrap($uploads) as $upload) {
            if ($upload instanceof TemporaryUploadedFile || $upload instanceof SplFileInfo) {
                $files[] = $upload;

                continue;
            }

            if (is_string($upload) && $upload !== '') {
                $files[] = $upload;
            }
        }

        return $files;
    }
}
