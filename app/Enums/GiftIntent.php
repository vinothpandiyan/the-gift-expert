<?php

namespace App\Enums;

enum GiftIntent: string
{
    case Romantic = 'romantic';
    case Sentimental = 'sentimental';
    case Personalised = 'personalised';
    case Practical = 'practical';
    case Fun = 'fun';
    case Surprising = 'surprising';
    case Premium = 'premium';
    case Experience = 'experience';
    case SelfCare = 'self_care';
    case Celebratory = 'celebratory';
}
