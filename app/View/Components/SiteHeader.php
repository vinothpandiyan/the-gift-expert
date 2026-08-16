<?php

namespace App\View\Components;

use App\Actions\Navigation\BuildPrimaryNavigationTreeAction;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class SiteHeader extends Component
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $navigation;

    public function __construct(BuildPrimaryNavigationTreeAction $buildPrimaryNavigationTree)
    {
        $this->navigation = $buildPrimaryNavigationTree->execute();
    }

    public function render(): View
    {
        return view('components.site-header');
    }
}
