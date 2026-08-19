<?php

namespace App\Enums;

enum CommercialExternalIdSource: string
{
    case Extracted = 'extracted';
    case UrlFingerprint = 'url_fingerprint';
    case None = 'none';
}
