<?php

namespace App\Filament\Pages;

use App\Actions\CuratedCatalog\PreviewCuratedProductIntakeAction;
use App\Actions\CuratedCatalog\ProcessCuratedProductIntakeAction;
use App\Actions\CuratedCatalog\ResolveCuratedProductIntakeProgressAction;
use App\CuratedCatalog\CuratedImageAcquisitionOutcome;
use App\CuratedCatalog\CuratedProductIntakeParseException;
use App\CuratedCatalog\CuratedProductIntakePreview;
use App\CuratedCatalog\CuratedProductIntakePreviewItem;
use App\Enums\CuratedProductIntakeRunStatus;
use App\Models\CuratedProductIntakeRun;
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

    public ?int $activeRunId = null;

    public ?int $syncItemsTotal = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $syncProgress = null;

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
                Section::make('Sync payload')
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
                                ->label('Sync Products')
                                ->color('success')
                                ->requiresConfirmation()
                                ->modalHeading('Sync curated products')
                                ->modalDescription('This will sync all valid items in this payload. AI runs only for new gifts. Processing continues in the background.')
                                ->action('confirmSync'),
                        ]),
                    ]),
                View::make('filament.pages.partials.curated-product-intake-results')
                    ->viewData(fn (): array => [
                        'previewSummary' => $this->previewSummary,
                        'previewRows' => $this->previewRows,
                        'commitResult' => $this->commitResult,
                        'activeRunId' => $this->activeRunId,
                        'syncItemsTotal' => $this->syncItemsTotal,
                        'syncProgress' => $this->syncProgress,
                    ]),
            ]);
    }

    public function previewImport(PreviewCuratedProductIntakeAction $previewAction): void
    {
        $this->commitResult = null;
        $this->activeRunId = null;
        $this->syncItemsTotal = null;
        $this->syncProgress = null;

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
                '%d valid, %d new, %d existing, %d ready to sync.',
                $preview->itemsValid,
                $preview->itemsNew,
                $preview->itemsExisting,
                $preview->itemsActionable,
            ))
            ->success()
            ->send();
    }

    public function confirmSync(ProcessCuratedProductIntakeAction $processAction): void
    {
        try {
            $started = $processAction->start(
                json: (string) ($this->data['payload'] ?? ''),
                formMerchantSlug: $this->nullableString($this->data['merchant_slug'] ?? null),
                formCurationGroup: $this->nullableString($this->data['curation_group'] ?? null),
                createdByUserId: auth()->id(),
            );
        } catch (CuratedProductIntakeParseException $exception) {
            Notification::make()
                ->title('Sync failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->activeRunId = $started->runId;
        $this->syncItemsTotal = $started->itemsActionable;
        $this->commitResult = null;
        $this->previewSummary = null;
        $this->previewRows = null;
        $this->syncProgress = [
            'run_id' => $started->runId,
            'status' => CuratedProductIntakeRunStatus::Processing->value,
            'display_phase' => 'starting',
            'total' => $started->itemsTotal,
            'processed' => 0,
            'remaining' => $started->itemsTotal,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'percentage' => 0,
            'is_terminal' => false,
            'show_worker_hint' => false,
            'error' => null,
        ];

        Notification::make()
            ->title('Sync started')
            ->body(sprintf('Syncing %d product(s) in the background.', $started->itemsActionable))
            ->success()
            ->send();
    }

    public function refreshSyncRun(
        ProcessCuratedProductIntakeAction $processAction,
        ResolveCuratedProductIntakeProgressAction $progressAction,
    ): void {
        if ($this->activeRunId === null) {
            return;
        }

        $run = CuratedProductIntakeRun::query()->find($this->activeRunId);

        if (! $run instanceof CuratedProductIntakeRun) {
            $this->activeRunId = null;
            $this->syncItemsTotal = null;
            $this->syncProgress = null;

            return;
        }

        $progress = $progressAction->execute($run);
        $this->syncProgress = $progress->toArray();

        if ($run->status === CuratedProductIntakeRunStatus::Processing) {
            return;
        }

        $result = $processAction->resultFromRun($run);

        $this->commitResult = [
            'run_id' => $result->runId,
            'status' => $result->status,
            'items_total' => $progress->total,
            'items_processed' => $result->itemsProcessed,
            'items_created' => $result->itemsCreated,
            'items_updated' => $result->itemsUpdated,
            'items_skipped' => $result->itemsSkipped,
            'items_failed' => $result->itemsFailed,
            'processed_items' => $result->processedItems,
            'is_complete' => $result->isComplete,
            'error' => $run->error,
        ];

        $this->activeRunId = null;
        $this->syncItemsTotal = null;
        $this->syncProgress = null;

        if ($run->status === CuratedProductIntakeRunStatus::Failed) {
            $title = 'Sync failed';
            $body = sprintf(
                '%d of %d products processed. Created %d, failed %d.',
                $progress->processed,
                $progress->total,
                $progress->created,
                $progress->failed,
            );

            if (is_string($run->error) && $run->error !== '') {
                $body .= ' '.$run->error;
            }

            Notification::make()
                ->title($title)
                ->body($body)
                ->danger()
                ->send();

            return;
        }

        if ($run->status === CuratedProductIntakeRunStatus::CompletedWithErrors) {
            Notification::make()
                ->title('Sync completed with issues')
                ->body(sprintf(
                    'Created %d, updated %d, skipped %d, failed %d.',
                    $result->itemsCreated,
                    $result->itemsUpdated,
                    $result->itemsSkipped,
                    $result->itemsFailed,
                ))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Sync finished')
            ->body(sprintf(
                'Created %d, updated %d, skipped %d, failed %d.',
                $result->itemsCreated,
                $result->itemsUpdated,
                $result->itemsSkipped,
                $result->itemsFailed,
            ))
            ->success()
            ->send();
    }

    private function hydratePreviewState(CuratedProductIntakePreview $preview): void
    {
        $warningCount = 0;

        foreach ($preview->items as $item) {
            if ($this->previewItemCountsAsWarning($item->warnings)) {
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

    /**
     * @param  list<string>  $warnings
     */
    private function previewItemCountsAsWarning(array $warnings): bool
    {
        $nonWarningCodes = [
            CuratedImageAcquisitionOutcome::STATUS_ACQUIRED,
            CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT,
        ];

        foreach ($warnings as $warning) {
            if (! in_array($warning, $nonWarningCodes, true)) {
                return true;
            }
        }

        return false;
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
