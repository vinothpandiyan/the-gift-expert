<?php

namespace App\Enums;

enum CatalogCandidateSourceType: string
{
    case Manual = 'manual';
    case Editorial = 'editorial';
    case Web = 'web';
    case Community = 'community';
    case Trend = 'trend';
    case Merchant = 'merchant';
    case Affiliate = 'affiliate';
    case AiResearch = 'ai_research';
}
