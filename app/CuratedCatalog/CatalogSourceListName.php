<?php

namespace App\CuratedCatalog;

final class CatalogSourceListName
{
    public static function normalize(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $normalized = str($name)->trim()->slug()->toString();

        return $normalized === '' ? null : $normalized;
    }
}
