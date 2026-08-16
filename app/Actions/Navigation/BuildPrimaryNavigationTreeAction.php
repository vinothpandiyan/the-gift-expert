<?php

namespace App\Actions\Navigation;

use App\Enums\NavigationItemType;
use App\Models\NavigationLink;
use App\Models\NavigationMenu;
use App\Models\NavigationSection;
use Illuminate\Support\Facades\Cache;

class BuildPrimaryNavigationTreeAction
{
    public const CACHE_KEY = 'navigation.primary.tree';

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        /** @var list<array<string, mixed>> */
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->build());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(): array
    {
        $resolver = app(ResolveNavigationHrefAction::class);

        $menus = NavigationMenu::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with([
                'sections' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
                'sections.links' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
            ])
            ->get();

        $tree = [];

        foreach ($menus as $menu) {
            $resolved = $this->resolveMenu($menu, $resolver);

            if ($resolved !== null) {
                $tree[] = $resolved;
            }
        }

        return $tree;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveMenu(NavigationMenu $menu, ResolveNavigationHrefAction $resolver): ?array
    {
        if ($menu->item_type === NavigationItemType::Link) {
            $href = $resolver->execute(
                $menu->link_type,
                $menu->linkable_id,
                $menu->route_key,
                $menu->url,
            );

            if ($href === null) {
                return null;
            }

            return [
                'label' => $menu->label,
                'slug' => $menu->slug,
                'item_type' => $menu->item_type->value,
                'sort_order' => $menu->sort_order,
                'href' => $href,
                'link_type' => $menu->link_type?->value,
                'opens_in_new_tab' => $menu->opens_in_new_tab,
            ];
        }

        $sections = [];

        foreach ($menu->sections as $section) {
            $resolved = $this->resolveSection($section, $resolver);

            if ($resolved !== null) {
                $sections[] = $resolved;
            }
        }

        if ($sections === []) {
            return null;
        }

        return [
            'label' => $menu->label,
            'slug' => $menu->slug,
            'item_type' => $menu->item_type->value,
            'sort_order' => $menu->sort_order,
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveSection(NavigationSection $section, ResolveNavigationHrefAction $resolver): ?array
    {
        $links = [];

        foreach ($section->links as $link) {
            $resolved = $this->resolveLink($link, $resolver);

            if ($resolved !== null) {
                $links[] = $resolved;
            }
        }

        if ($links === []) {
            return null;
        }

        return [
            'heading' => $section->heading,
            'appearance' => $section->appearance->value,
            'links' => $links,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveLink(NavigationLink $link, ResolveNavigationHrefAction $resolver): ?array
    {
        $href = $resolver->execute(
            $link->link_type,
            $link->linkable_id,
            $link->route_key,
            $link->url,
        );

        if ($href === null) {
            return null;
        }

        return [
            'label' => $link->label,
            'href' => $href,
            'link_type' => $link->link_type->value,
            'opens_in_new_tab' => $link->opens_in_new_tab,
        ];
    }
}
