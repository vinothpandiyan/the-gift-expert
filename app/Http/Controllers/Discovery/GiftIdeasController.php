<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Support\PageMeta;
use Illuminate\View\View;

class GiftIdeasController extends Controller
{
    public function index(): View
    {
        $active = fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');

        return view('discovery.gift-ideas.index', [
            'recipientTypes' => RecipientType::query()->tap($active)->get(),
            'relationships' => Relationship::query()->tap($active)->get(),
            'occasions' => Occasion::query()->tap($active)->get(),
            'interests' => Interest::query()->tap($active)->get(),
            'professions' => Profession::query()->tap($active)->get(),
            'giftTypes' => GiftType::query()->tap($active)->get(),
            'categories' => Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'seoTitle' => PageMeta::giftIdeasTitle(),
            'seoDescription' => PageMeta::giftIdeasDescription(),
            'seoCanonical' => PageMeta::giftIdeasCanonical(),
            'seoRobots' => 'index, follow',
            'breadcrumbs' => PageMeta::giftIdeasBreadcrumbs(),
        ]);
    }
}
