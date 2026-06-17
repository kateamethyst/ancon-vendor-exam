<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;


class FeeCalculatorService
{
    /**
     * surcharge_applies_to: surcharge is calculated on (base_total + manifest_fee).
     */
    public const SURCHARGE_BASE_PLUS_MANIFEST_FEE = 'base_plus_manifest_fee';

    /**
     * surcharge_applies_to: surcharge is calculated on base_total only
     * (the manifest fee is added after the surcharge).
     */
    public const SURCHARGE_BASE_ONLY = 'base_only';

    /**
     * Default vendor configuration values.
     */
    public const VENDOR_CONFIG_DEFAULTS = [
        'manifest_fee' => 25.00,
        'surcharge_percent' => 8.7,
        'surcharge_applies_to' => self::SURCHARGE_BASE_PLUS_MANIFEST_FEE,
    ];

    /**
     * Calculate the per-manifest fee breakdown for a set of invoice lines.
     *
     * @param iterable<array{
     *     line_number?: int|string,
     *     manifest_number: string,
     *     description?: string,
     *     amount: int|float|string
     * }> $lines        Invoice lines. Plain arrays or any iterable/collection.
     * @param array{
     *     manifest_fee?: int|float|string,
     *     surcharge_percent?: int|float|string,
     *     surcharge_applies_to?: string
     * } $vendorConfig  Vendor-specific fee configuration.
     *
     * @return array{
     *     manifests: array<string, array{
     *         base_total: string,
     *         manifest_fee: string,
     *         subtotal: string,
     *         surcharge: string,
     *         manifest_total: string
     *     }>,
     *     invoice_total: string
     * }
     */
    public function calculate(iterable $lines, array $vendorConfig = []): array
    {
        $config = array_merge(self::VENDOR_CONFIG_DEFAULTS, $vendorConfig);
        
        $manifestFeeCents = $this->toCents($config['manifest_fee']);
        $surchargePercent = (string) $config['surcharge_percent'];
        $surchargeAppliesTo = $config['surcharge_applies_to'];

        $this->assertValidSurchargeTarget($surchargeAppliesTo);

        // Group lines by manifest number, preserving first-seen order.
        $groups = $this->groupByManifest($lines);

        $manifests = [];
        $invoiceTotalCents = 0;

        foreach ($groups as $manifestNumber => $group) {
            $baseTotalCents = 0;
            foreach ($group['amounts'] as $amountCents) {
                $baseTotalCents += $amountCents;
            }

            $subtotalCents = $baseTotalCents + $manifestFeeCents;

            // The amount the surcharge percentage is applied to.
            $surchargeBaseCents = $surchargeAppliesTo === self::SURCHARGE_BASE_ONLY
                ? $baseTotalCents
                : $subtotalCents;

            $surchargeCents = $this->percentOfCents($surchargeBaseCents, $surchargePercent);

            $manifestTotalCents = $subtotalCents + $surchargeCents;

            $invoiceTotalCents += $manifestTotalCents;

            $manifests[$manifestNumber] = [
                'base_total'     => $this->centsToDecimal($baseTotalCents),
                'manifest_fee'   => $this->centsToDecimal($manifestFeeCents),
                'subtotal'       => $this->centsToDecimal($subtotalCents),
                'surcharge'      => $this->centsToDecimal($surchargeCents),
                'manifest_total' => $this->centsToDecimal($manifestTotalCents),
            ];
        }

        return [
            'manifests'     => $manifests,
            'invoice_total' => $this->centsToDecimal($invoiceTotalCents),
        ];
    }

    /**
     * Group line amounts (in cents) by manifest number, preserving the order
     * in which each manifest first appears on the invoice.
     *
     * @param iterable<array<string, mixed>> $lines
     * @return array<string, array{amounts: array<int, int>, line_numbers: array<int, int|string>}>
     */
    private function groupByManifest(iterable $lines): array
    {
        $groups = [];

        foreach ($lines as $index => $line) {
            if (!isset($line['manifest_number']) || $line['manifest_number'] === '') {
                throw new InvalidArgumentException(
                    "Invoice line at position {$index} is missing a manifest_number."
                );
            }

            if (!array_key_exists('amount', $line)) {
                throw new InvalidArgumentException(
                    "Invoice line at position {$index} is missing an amount."
                );
            }

            $manifest = (string) $line['manifest_number'];

            if (!isset($groups[$manifest])) {
                $groups[$manifest] = ['amounts' => [], 'line_numbers' => []];
            }

            $groups[$manifest]['amounts'][] = $this->toCents($line['amount']);
            $groups[$manifest]['line_numbers'][] = $line['line_number'] ?? $index;
        }

        return $groups;
    }

    /**
     * Convert a dollar amount (int, float, or numeric string) to integer cents,
     * rounding half away from zero to the nearest cent.
     */
    private function toCents(int|float|string $amount): int
    {
        if (!is_numeric($amount)) {
            throw new InvalidArgumentException('Amount must be numeric, got: ' . var_export($amount, true));
        }

        // Parse the numeric string into an exact integer-cents value, so a
        // value like "500.00" never drifts through a float multiplication.
        [$num, $scale] = $this->parseDecimal($amount);

        // num / scale dollars  ==  (num * 100) / scale cents.
        return $this->roundDiv($num * 100, $scale);
    }

    /**
     * Compute (cents * percent / 100), rounded half away from zero to the
     * nearest whole cent, using exact integer arithmetic.
     */
    private function percentOfCents(int $cents, int|float|string $percent): int
    {
        // percent == pctNum / pctScale, so:
        // cents * percent / 100 == (cents * pctNum) / (pctScale * 100).
        [$pctNum, $pctScale] = $this->parseDecimal($percent);

        return $this->roundDiv($cents * $pctNum, $pctScale * 100);
    }

    /**
     * Parse a numeric value into an integer numerator and a power-of-ten scale
     * such that value === numerator / scale, exactly.
     *
     * e.g. "8.7" -> [87, 10];  500.00 -> [500, 1];  "-1.250" -> [-125, 100].
     *
     * @return array{0: int, 1: int}
     */
    private function parseDecimal(int|float|string $value): array
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Value must be numeric, got: ' . var_export($value, true));
        }

        $s = (string) $value;
        $negative = str_starts_with($s, '-');
        $s = ltrim($s, '+-');

        if (str_contains($s, '.')) {
            [$intPart, $fracPart] = explode('.', $s, 2);
        } else {
            $intPart = $s;
            $fracPart = '';
        }

        // Trailing zeros don't change the value but needlessly inflate scale.
        $fracPart = rtrim($fracPart, '0');

        $digits = ($intPart === '' ? '0' : $intPart) . $fracPart;
        $num = (int) $digits;
        $scale = 10 ** strlen($fracPart);

        return [$negative ? -$num : $num, $scale];
    }

    /**
     * Integer division of $numerator / $denominator, rounded to the nearest
     * integer using "half away from zero" rounding.
     */
    private function roundDiv(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        $sign = (($numerator < 0) !== ($denominator < 0)) ? -1 : 1;
        $n = abs($numerator);
        $d = abs($denominator);

        $quotient = intdiv($n, $d);
        $remainder = $n % $d;

        if ($remainder * 2 >= $d) {
            $quotient++;
        }

        return $sign * $quotient;
    }

    /**
     * Format integer cents as a fixed two-decimal dollar string, e.g. 107070 -> "1070.70".
     */
    private function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    private function assertValidSurchargeTarget(string $surchargeAppliesTo): void
    {
        $allowed = [self::SURCHARGE_BASE_PLUS_MANIFEST_FEE, self::SURCHARGE_BASE_ONLY];

        if (!in_array($surchargeAppliesTo, $allowed, true)) {
            throw new InvalidArgumentException(
                "Unsupported surcharge_applies_to '{$surchargeAppliesTo}'. "
                . 'Allowed: ' . implode(', ', $allowed) . '.'
            );
        }
    }
}
