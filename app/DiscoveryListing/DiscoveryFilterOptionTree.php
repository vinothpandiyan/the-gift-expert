<?php

namespace App\DiscoveryListing;

use Illuminate\Support\Collection;

final class DiscoveryFilterOptionTree
{
    /**
     * Nest already-resolved Category options using `parentId`.
     *
     * Children whose parent is not in the visible set render as roots.
     * This is a pure in-memory transform — it must not query the database.
     *
     * @param  Collection<int, DiscoveryFilterOption>  $options
     * @return list<array{option: DiscoveryFilterOption, children: list<DiscoveryFilterOption>}>
     */
    public static function nest(Collection $options): array
    {
        $visibleIds = [];

        foreach ($options as $option) {
            $visibleIds[$option->id] = true;
        }

        $childrenByParent = [];
        $roots = [];

        foreach ($options as $option) {
            $parentId = $option->parentId;

            if ($parentId !== null && isset($visibleIds[$parentId])) {
                $childrenByParent[$parentId][] = $option;

                continue;
            }

            $roots[] = $option;
        }

        $tree = [];

        foreach ($roots as $root) {
            $tree[] = [
                'option' => $root,
                'children' => $childrenByParent[$root->id] ?? [],
            ];
        }

        return $tree;
    }
}
