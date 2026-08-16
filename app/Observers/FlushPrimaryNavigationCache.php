<?php

namespace App\Observers;

use App\Actions\Navigation\BuildPrimaryNavigationTreeAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class FlushPrimaryNavigationCache
{
    public function saved(Model $model): void
    {
        $this->flush();
    }

    public function deleted(Model $model): void
    {
        $this->flush();
    }

    public function restored(Model $model): void
    {
        $this->flush();
    }

    public function forceDeleted(Model $model): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        Cache::forget(BuildPrimaryNavigationTreeAction::CACHE_KEY);
    }
}
