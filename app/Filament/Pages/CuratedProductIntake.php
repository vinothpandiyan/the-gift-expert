<?php

namespace App\Filament\Pages;

use App\Actions\CuratedCatalog\PreviewCuratedProductIntakeAction;
use App\Actions\CuratedCatalog\ProcessCuratedProductIntakeAction;
use App\CuratedCatalog\CuratedProductIntakeParseException;
use App\CuratedCatalog\CuratedProductIntakePreview;
use App\CuratedCatalog\CuratedProductIntakePreviewItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class CuratedProductIntake extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Curated Product Intake';

    protected static ?string $title = 'Curated Product Intake';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $previewSummary = null;

    /**
     * @var list<array<string, mixed>>|null
     */
    public ?array $previewRows = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $commitResult = null;

    public function mount(): void
    {
        $this->data = [
            'merchant_slug' => array_key_first($this->merchantOptions()) ?? 'amazon-in',
            'curation_group' => null,
            'payload' => '',
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Import payload')
                    ->schema([
                        Select::make('merchant_slug')
                            ->label('Merchant')
                            ->options($this->merchantOptions())
                            ->required()
                            ->native(false),
                        Select::make('curation_group')
                            ->label('Curation group')
                            ->options([
                                'men' => 'Men',
                                'women' => 'Women',
                                'unspecified' => 'Unspecified',
                            ])
                            ->nullable()
                            ->native(false),
                        Textarea::make('payload')
                            ->label('Browser JSON')
                            ->rows(14)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('curated-product-intake-form')
                    ->footer([
                        Actions::make([
                            Action::make('preview')
                                ->label('Preview')
                                ->action('previewImport'),
                            Action::make('confirm')
                                ->label('Confirm import')
                                ->color('success')
                                ->requiresConfirmation()
                                ->modalHeading('Confirm curated import')
                                ->modalDescription('This will process up to '.config('curated_catalog.max_items_per_commit', 25).' actionable items in this request. AI runs only for new products.')
                                ->action('confirmImport'),
                        ]),
                    ]),
                View::make('filament.pages.partials.curated-product-intake-results')
                    ->viewData(fn (): array => [
                        'previewSummary' => $this->previewSummary,
                        'previewRows' => $this->previewRows,
                        'commitResult' => $this->commitResult,
                    ]),
            ]);
    }

    public function previewImport(PreviewCuratedProductIntakeAction $previewAction): void
    {
        $this->commitResult = null;

        try {
            $preview = $previewAction->execute(
                json: (string) ($this->data['payload'] ?? ''),
                formMerchantSlug: $this->nullableString($this->data['merchant_slug'] ?? null),
                formCurationGroup: $this->nullableString($this->data['curation_group'] ?? null),
            );
        } catch (CuratedProductIntakeParseException $exception) {
            $this->previewSummary = null;
            $this->previewRows = null;

            Notification::make()
                ->title('Preview failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->hydratePreviewState($preview);

        Notification::make()
            ->title('Preview ready')
            ->body(sprintf(
                '%d valid, %d new, %d existing, %d actionable this commit (max %d).',
                $preview->itemsValid,
                $preview->itemsNew,
                $preview->itemsExisting,
                min($preview->itemsActionable, (int) config('curated_catalog.max_items_per_commit', 25)),
                (int) config('curated_catalog.max_items_per_commit', 25),
            ))
            ->success()
            ->send();
    }

    public function confirmImport(ProcessCuratedProductIntakeAction $processAction): void
    {
        try {
            $result = $processAction->execute(
                json: (string) ($this->data['payload'] ?? ''),
                formMerchantSlug: $this->nullableString($this->data['merchant_slug'] ?? null),
                formCurationGroup: $this->nullableString($this->data['curation_group'] ?? null),
                createdByUserId: auth()->id(),
            );
        } catch (CuratedProductIntakeParseException $exception) {
            Notification::make()
                ->title('Import failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->commitResult = [
            'run_id' => $result->runId,
            'items_processed' => $result->itemsProcessed,
            'items_created' => $result->itemsCreated,
            'items_updated' => $result->itemsUpdated,
            'items_skipped' => $result->itemsSkipped,
            'items_failed' => $result->itemsFailed,
            'items_remaining' => $result->itemsRemaining,
            'processed_items' => $result->processedItems,
        ];

        $this->previewSummary = null;
        $this->previewRows = null;

        $message = sprintf(
            'Created %d, updated %d, skipped %d, failed %d.',
            $result->itemsCreated,
            $result->itemsUpdated,
            $result->itemsSkipped,
            $result->itemsFailed,
        );

        if ($result->itemsRemaining > 0) {
            $message .= ' '.$result->itemsRemaining.' actionable item(s) remain — run import again.';
        }

        Notification::make()
            ->title('Import finished')
            ->body($message)
            ->success()
            ->send();
    }

    private function hydratePreviewState(CuratedProductIntakePreview $preview): void
    {
        $maxPerCommit = (int) config('curated_catalog.max_items_per_commit', 25);

        $warningCount = 0;

        foreach ($preview->items as $item) {
            if ($item->warnings !== []) {
                $warningCount++;
            }
        }

        $this->previewSummary = [
            'merchant_slug' => $preview->merchantSlug,
            'items_total' => $preview->itemsTotal,
            'items_valid' => $preview->itemsValid,
            'items_invalid' => $preview->itemsInvalid,
            'items_new' => $preview->itemsNew,
            'items_existing' => $preview->itemsExisting,
            'items_duplicate' => $preview->itemsDuplicate,
            'items_trashed' => $preview->itemsTrashed,
            'items_missing_price' => $preview->itemsMissingPrice,
            'items_missing_image' => $preview->itemsMissingImage,
            'items_unavailable' => $preview->itemsUnavailable,
            'items_affiliate_not_ready' => $preview->itemsAffiliateNotReady,
            'items_with_warnings' => $warningCount,
            'items_actionable' => $preview->itemsActionable,
            'items_actionable_this_commit' => min($preview->itemsActionable, $maxPerCommit),
            'items_remaining_after_commit' => max(0, $preview->itemsActionable - $maxPerCommit),
            'max_items_per_commit' => $maxPerCommit,
        ];

        $this->previewRows = array_map(
            fn (CuratedProductIntakePreviewItem $item): array => [
                'item_index' => $item->itemIndex,
                'asin' => $item->externalProductId(),
                'title' => $this->decodePreviewText($item->title()),
                'price_display' => $this->formatCuratedPrice($item->priceAmount(), $item->input?->priceCurrency),
                'has_image' => $item->sourceImageUrl() !== null,
                'source_image_url' => $item->sourceImageUrl(),
                'availability_label' => $this->formatAvailabilityLabel($item->availability()),
                'availability_tone' => $this->availabilityTone($item->availability()),
                'disposition_label' => strtoupper($item->disposition),
                'action_label' => $this->formatActionLabel($item->proposedAction),
                'action_tone' => $this->actionTone($item->proposedAction),
                'affiliate_ready' => $item->affiliateReady,
                'warnings' => $item->warnings,
                'error' => $item->error?->code,
            ],
            $preview->items,
        );
    }

    private function decodePreviewText(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function formatCuratedPrice(?string $amount, ?string $currency): ?string
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }

        $value = (float) $amount;
        $currency = strtoupper($currency ?? 'INR');

        if ($currency === 'INR') {
            $formatted = fmod($value, 1.0) === 0.0
                ? number_format($value, 0)
                : number_format($value, 2);

            return '₹'.$formatted;
        }

        return $currency.' '.number_format($value, 2);
    }

    private function formatAvailabilityLabel(?string $availability): string
    {
        return match ($availability) {
            'in_stock' => 'In stock',
            'out_of_stock' => 'Out of stock',
            'unavailable' => 'Unavailable',
            'unknown', null, '' => 'Unknown',
            default => ucwords(str_replace('_', ' ', $availability)),
        };
    }

    private function availabilityTone(?string $availability): string
    {
        return match ($availability) {
            'in_stock' => 'success',
            'out_of_stock', 'unavailable' => 'danger',
            default => 'default',
        };
    }

    private function formatActionLabel(string $action): string
    {
        return match ($action) {
            'CREATE' => 'Create',
            'UPDATE' => 'Update',
            'SKIP' => 'Skip',
            'FAIL' => 'Fail',
            default => $action,
        };
    }

    private function actionTone(string $action): string
    {
        return match ($action) {
            'CREATE' => 'success',
            'UPDATE' => 'info',
            'FAIL' => 'danger',
            default => 'default',
        };
    }

    /**
     * @return array<string, string>
     */
    private function merchantOptions(): array
    {
        $options = [];

        foreach (config('curated_catalog.merchants', []) as $slug => $merchantConfig) {
            if (! is_array($merchantConfig) || ($merchantConfig['enabled'] ?? false) !== true) {
                continue;
            }

            $options[$slug] = $slug;
        }

        return $options;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? 'Curated Product Intake';
    }

    public function getTitle(): string|Htmlable
    {
        return static::$title ?? 'Curated Product Intake';
    }
}
