<?php

namespace App\CommercialSourcing;

class ExtractCommercialOfferPrice
{
    /**
     * @return array{amount: string, currency: string}|null
     */
    public function execute(string $snippet): ?array
    {
        $amounts = [];

        $patterns = [
            '/₹\s*([0-9]{1,3}(?:,[0-9]{2,3})*(?:\.[0-9]{1,2})?|[0-9]+(?:\.[0-9]{1,2})?)/u',
            '/(?:Rs\.?|INR)\s*([0-9]{1,3}(?:,[0-9]{2,3})*(?:\.[0-9]{1,2})?|[0-9]+(?:\.[0-9]{1,2})?)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $snippet, $groups) === false) {
                continue;
            }

            foreach ($groups[1] ?? [] as $raw) {
                if (! is_string($raw)) {
                    continue;
                }

                $normalized = $this->normalizeAmount($raw);

                if ($normalized !== null) {
                    $amounts[] = $normalized;
                }
            }
        }

        $unique = array_values(array_unique($amounts));

        if (count($unique) !== 1) {
            return null;
        }

        return [
            'amount' => $unique[0],
            'currency' => 'INR',
        ];
    }

    private function normalizeAmount(string $raw): ?string
    {
        $stripped = str_replace(',', '', trim($raw));

        if ($stripped === '' || ! is_numeric($stripped)) {
            return null;
        }

        return number_format((float) $stripped, 2, '.', '');
    }
}
