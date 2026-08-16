<?php

namespace App\Rules;

use App\Support\SeoLandingPageEditorial;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NotExistingTaxonomySlug implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (SeoLandingPageEditorial::taxonomySlugTaken($value)) {
            $fail('This slug matches an existing taxonomy or category slug. Use a compound editorial slug instead.');
        }
    }
}
