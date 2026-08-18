<?php

namespace App\Support;

final class CatalogCandidateTitleFingerprint
{
    public static function from(string $title): string
    {
        $normalized = mb_strtolower(trim($title), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';
        $normalized = trim($normalized);

        return hash('sha256', $normalized);
    }
}
