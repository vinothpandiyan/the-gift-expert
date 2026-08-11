<?php

namespace App\Support;

/**
 * Customer-facing gift terminology. Internal code and models use "Product".
 */
final class Terminology
{
    public static function gift(): string
    {
        return 'Gift';
    }

    public static function gifts(): string
    {
        return 'Gifts';
    }

    public static function giftIdeas(): string
    {
        return 'Gift Ideas';
    }

    public static function giftRecommendations(): string
    {
        return 'Gift Recommendations';
    }

    public static function giftPage(): string
    {
        return 'Gift Page';
    }
}
