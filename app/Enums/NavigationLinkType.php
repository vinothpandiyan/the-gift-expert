<?php

namespace App\Enums;

enum NavigationLinkType: string
{
    case Relationship = 'relationship';
    case Occasion = 'occasion';
    case Interest = 'interest';
    case Profession = 'profession';
    case RecipientType = 'recipient_type';
    case GiftType = 'gift_type';
    case Category = 'category';
    case SeoLandingPage = 'seo_landing_page';
    case DiscoveryRoute = 'discovery_route';
    case ExternalUrl = 'external_url';
}
