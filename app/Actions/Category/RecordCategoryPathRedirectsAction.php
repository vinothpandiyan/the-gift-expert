<?php

namespace App\Actions\Category;

use App\Models\CategoryPathRedirect;
use Illuminate\Support\Facades\DB;

class RecordCategoryPathRedirectsAction
{
    /**
     * @param  array<string, string>  $pathChanges
     */
    public function execute(array $pathChanges): void
    {
        DB::transaction(function () use ($pathChanges): void {
            foreach ($pathChanges as $fromPath => $toPath) {
                $this->record($fromPath, $toPath);
            }
        });
    }

    public function record(string $fromPath, string $toPath): void
    {
        if ($fromPath === $toPath) {
            return;
        }

        CategoryPathRedirect::query()
            ->where('to_path', $fromPath)
            ->update(['to_path' => $toPath]);

        CategoryPathRedirect::query()->updateOrCreate(
            ['from_path' => $fromPath],
            ['to_path' => $toPath],
        );
    }
}
