<?php

namespace App\Console\Commands;

use App\Actions\CuratedCatalog\ValidateCuratedProductImageUrlAction;
use App\Actions\Import\AcquireRemoteProductImageAction;
use App\Actions\ProductImage\DetectSafeOuterBackgroundTrimAction;
use App\Actions\ProductImage\NormalizeAmazonProductImageUrlAction;
use App\Actions\ProductImage\ProcessProductImageAction;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Throwable;

class AuditAmazonImageFidelityCommand extends Command
{
    protected $signature = 'catalog:audit-amazon-image-fidelity
        {--product=* : Product IDs to include in the non-mutating pilot}
        {--label=pilot : Output folder label}';

    protected $description = 'Compare the recorded Amazon rendition, source-fidelity rendition, stored derivative, and fidelity-preserving derivative without database writes.';

    public function handle(
        NormalizeAmazonProductImageUrlAction $normalizeUrl,
        ValidateCuratedProductImageUrlAction $validateUrl,
        AcquireRemoteProductImageAction $acquire,
        DetectSafeOuterBackgroundTrimAction $detectTrim,
        ProcessProductImageAction $process,
    ): int {
        $productIds = collect($this->option('product'))
            ->filter(fn (mixed $id): bool => is_string($id) && ctype_digit($id) && (int) $id > 0)
            ->map(fn (string $id): int => (int) $id)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            $this->error('Pass at least one --product=<id>.');

            return self::FAILURE;
        }

        $products = Product::query()
            ->with(['images', 'affiliateLinks.merchant'])
            ->whereKey($productIds)
            ->get()
            ->keyBy('id');
        $folder = storage_path('app/image-fidelity-pilot/'.Str::slug((string) $this->option('label')));
        File::ensureDirectoryExists($folder);
        $rows = [];
        $failed = 0;

        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            $image = $product?->images->firstWhere('is_primary', true) ?? $product?->images->first();
            $merchant = $product?->affiliateLinks->firstWhere('is_primary', true)?->merchant
                ?? $product?->affiliateLinks->first()?->merchant;

            if ($product === null || $image === null || $merchant === null || blank($image->source_url)) {
                $failed++;
                $this->warn("product={$productId} skipped: missing product, source image, or merchant.");

                continue;
            }

            $productFolder = $folder.'/'.$product->id.'-'.Str::slug($product->name);
            File::deleteDirectory($productFolder);
            File::ensureDirectoryExists($productFolder);
            $downloads = [];

            try {
                $storedBinary = Storage::disk($image->disk ?: (string) config('media.product_images.disk', 'public'))
                    ->get($image->path);
                File::put($productFolder.'/01-stored-before.'.($this->extension($storedBinary)), $storedBinary);
                $recordedUrl = (string) $image->source_url;
                $normalized = $normalizeUrl->execute($recordedUrl, $merchant);

                foreach ([
                    '02-recorded-amazon-source' => $recordedUrl,
                    '03-source-fidelity-rendition' => $normalized->url,
                ] as $name => $url) {
                    $download = $acquire->execute(
                        $url,
                        fn (string $candidate): mixed => $validateUrl->execute(
                            $candidate,
                            config('curated_catalog.image_acquisition.amazon.hosts', []),
                            httpsOnly: true,
                        ),
                    );
                    $downloads[] = $download->path;
                    $binary = File::get($download->path);
                    File::put($productFolder.'/'.$name.'.'.$this->extension($binary), $binary);
                }

                $sourceFidelityPath = $downloads[array_key_last($downloads)];
                $trim = $detectTrim->execute($sourceFidelityPath);
                $processed = $process->execute(
                    $sourceFidelityPath,
                    $trim->shouldTrim ? $trim->cropBox : null,
                    preserveComposition: true,
                );
                File::put($productFolder.'/04-processed.'.$processed->extension, $processed->contents);

                $files = collect(File::files($productFolder))
                    ->sortBy(fn (\SplFileInfo $file): string => $file->getFilename())
                    ->map(fn (\SplFileInfo $file): array => $this->fileFacts($file))
                    ->values()
                    ->all();
                $rows[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'recorded_source_url' => $recordedUrl,
                    'normalized_source_url' => $normalized->url,
                    'trim_decision' => $trim->reason,
                    'trimmed' => $trim->shouldTrim,
                    'files' => $files,
                ];
                $this->line("product={$product->id} compared trim={$trim->reason}");
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("product={$productId} failed: {$exception->getMessage()}");
            } finally {
                foreach ($downloads as $temporaryPath) {
                    File::delete($temporaryPath);
                }
            }
        }

        File::put(
            $folder.'/report.json',
            json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        File::put($folder.'/report.html', $this->htmlReport($rows));
        $this->info('Pilot report: '.$folder.'/report.html');
        $this->comment('No Product, ProductImage, taxonomy, editorial, SEO, or publication records were changed.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function extension(string $binary): string
    {
        return match ((new \finfo(FILEINFO_MIME_TYPE))->buffer($binary)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    /**
     * @return array{file: string, width: int, height: int, bytes: int}
     */
    private function fileFacts(\SplFileInfo $file): array
    {
        $info = getimagesize($file->getPathname());

        return [
            'file' => $file->getFilename(),
            'width' => (int) ($info[0] ?? 0),
            'height' => (int) ($info[1] ?? 0),
            'bytes' => $file->getSize(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function htmlReport(array $rows): string
    {
        $sections = collect($rows)->map(function (array $row): string {
            $folder = $row['product_id'].'-'.Str::slug((string) $row['name']);
            $figures = collect($row['files'])->map(function (array $file) use ($folder): string {
                $src = $folder.'/'.rawurlencode((string) $file['file']);
                $caption = htmlspecialchars(sprintf(
                    '%s — %d×%d, %s',
                    $file['file'],
                    $file['width'],
                    $file['height'],
                    Number::fileSize((int) $file['bytes']),
                ));

                return "<figure><div class=\"canvas\"><img src=\"{$src}\" alt=\"\"></div><figcaption>{$caption}</figcaption></figure>";
            })->implode('');
            $name = htmlspecialchars((string) $row['name']);
            $trim = htmlspecialchars((string) $row['trim_decision']);

            return "<section><h2>#{$row['product_id']} {$name}</h2><p>Trim decision: {$trim}</p><div class=\"grid\">{$figures}</div></section>";
        })->implode('');

        return '<!doctype html><html><head><meta charset="utf-8"><title>Amazon image fidelity pilot</title><style>'
            .'body{font:14px system-ui;margin:24px;color:#222}section{margin:0 0 40px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}'
            .'.canvas{aspect-ratio:1;border:1px solid #ddd;background:#f8f8f8;padding:12px;display:flex;align-items:center;justify-content:center}.canvas img{max-width:100%;max-height:100%;object-fit:contain}figcaption{margin-top:6px;overflow-wrap:anywhere}@media(max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}'
            .'</style></head><body><h1>Amazon image fidelity pilot</h1><p>Columns: stored before, recorded Amazon source, source-fidelity rendition, processed/browser containment simulation.</p>'
            .$sections.'</body></html>';
    }
}
