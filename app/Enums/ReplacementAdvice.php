<?php

namespace App\Enums;

enum ReplacementAdvice: string
{
    case ReplacementRecommended = 'replacement_recommended';
    case ReplacementUncertain = 'replacement_uncertain';
    case NotAReplacement = 'not_a_replacement';
}
