<?php

namespace App\Enums;

enum CatalogCandidateIngestionFormat: string
{
    case Csv = 'csv';
    case Json = 'json';
}
