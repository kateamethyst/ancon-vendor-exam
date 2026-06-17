<?php

namespace Tests\Unit;

use App\Services\FeeCalculatorService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FeeCalculatorServiceTest extends TestCase
{
    private FeeCalculatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FeeCalculatorService();
    }

    public function test_basic_calculation_with_single_manifest(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
            ['manifest_number' => 'M001', 'amount' => 50.00],
        ];

        $config = [
            'manifest_fee' => 10.00,
            'surcharge_percent' => 5,
            'surcharge_applies_to' => FeeCalculatorService::SURCHARGE_BASE_PLUS_MANIFEST_FEE,
        ];

        $result = $this->service->calculate($lines, $config);

        $this->assertCount(1, $result['manifests']);
        $this->assertEquals('150.00', $result['manifests']['M001']['base_total']);
        $this->assertEquals('10.00', $result['manifests']['M001']['manifest_fee']);
        $this->assertEquals('160.00', $result['manifests']['M001']['subtotal']);
        $this->assertEquals('8.00', $result['manifests']['M001']['surcharge']);
        $this->assertEquals('168.00', $result['manifests']['M001']['manifest_total']);
        $this->assertEquals('168.00', $result['invoice_total']);
    }

    public function test_calculation_with_multiple_manifests(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
            ['manifest_number' => 'M002', 'amount' => 200.00],
            ['manifest_number' => 'M001', 'amount' => 50.00],
        ];

        $config = [
            'manifest_fee' => 10.00,
            'surcharge_percent' => 5,
        ];

        $result = $this->service->calculate($lines, $config);

        $this->assertCount(2, $result['manifests']);
        
        // M001: 100 + 50 = 150 base, +10 fee = 160 subtotal, +8 surcharge = 168 total
        $this->assertEquals('150.00', $result['manifests']['M001']['base_total']);
        $this->assertEquals('10.00', $result['manifests']['M001']['manifest_fee']);
        $this->assertEquals('160.00', $result['manifests']['M001']['subtotal']);
        $this->assertEquals('8.00', $result['manifests']['M001']['surcharge']);
        $this->assertEquals('168.00', $result['manifests']['M001']['manifest_total']);
        
        // M002: 200 base, +10 fee = 210 subtotal, +10.50 surcharge = 220.50 total
        $this->assertEquals('200.00', $result['manifests']['M002']['base_total']);
        $this->assertEquals('10.00', $result['manifests']['M002']['manifest_fee']);
        $this->assertEquals('210.00', $result['manifests']['M002']['subtotal']);
        $this->assertEquals('10.50', $result['manifests']['M002']['surcharge']);
        $this->assertEquals('220.50', $result['manifests']['M002']['manifest_total']);
        
        $this->assertEquals('388.50', $result['invoice_total']);
    }

    public function test_surcharge_applies_to_base_only(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
        ];

        $config = [
            'manifest_fee' => 10.00,
            'surcharge_percent' => 5,
            'surcharge_applies_to' => FeeCalculatorService::SURCHARGE_BASE_ONLY,
        ];

        $result = $this->service->calculate($lines, $config);

        // Surcharge on base only: 100 * 5% = 5.00
        $this->assertEquals('100.00', $result['manifests']['M001']['base_total']);
        $this->assertEquals('10.00', $result['manifests']['M001']['manifest_fee']);
        $this->assertEquals('110.00', $result['manifests']['M001']['subtotal']);
        $this->assertEquals('5.00', $result['manifests']['M001']['surcharge']);
        $this->assertEquals('115.00', $result['manifests']['M001']['manifest_total']);
    }

    public function test_surcharge_applies_to_base_plus_manifest_fee(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
        ];

        $config = [
            'manifest_fee' => 10.00,
            'surcharge_percent' => 5,
            'surcharge_applies_to' => FeeCalculatorService::SURCHARGE_BASE_PLUS_MANIFEST_FEE,
        ];

        $result = $this->service->calculate($lines, $config);

        // Surcharge on base + fee: (100 + 10) * 5% = 5.50
        $this->assertEquals('100.00', $result['manifests']['M001']['base_total']);
        $this->assertEquals('10.00', $result['manifests']['M001']['manifest_fee']);
        $this->assertEquals('110.00', $result['manifests']['M001']['subtotal']);
        $this->assertEquals('5.50', $result['manifests']['M001']['surcharge']);
        $this->assertEquals('115.50', $result['manifests']['M001']['manifest_total']);
    }

    public function test_zero_manifest_fee(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
        ];

        $config = [
            'manifest_fee' => 0,
            'surcharge_percent' => 5,
        ];

        $result = $this->service->calculate($lines, $config);

        $this->assertEquals('100.00', $result['manifests']['M001']['base_total']);
        $this->assertEquals('0.00', $result['manifests']['M001']['manifest_fee']);
        $this->assertEquals('100.00', $result['manifests']['M001']['subtotal']);
        $this->assertEquals('5.00', $result['manifests']['M001']['surcharge']);
        $this->assertEquals('105.00', $result['manifests']['M001']['manifest_total']);
    }

    public function test_zero_surcharge_percent(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
        ];

        $config = [
            'manifest_fee' => 10.00,
            'surcharge_percent' => 0,
        ];

        $result = $this->service->calculate($lines, $config);

        $this->assertEquals('100.00', $result['manifests']['M001']['base_total']);
        $this->assertEquals('10.00', $result['manifests']['M001']['manifest_fee']);
        $this->assertEquals('110.00', $result['manifests']['M001']['subtotal']);
        $this->assertEquals('0.00', $result['manifests']['M001']['surcharge']);
        $this->assertEquals('110.00', $result['manifests']['M001']['manifest_total']);
    }

    public function test_missing_manifest_fee_defaults_to_default(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
        ];

        $config = [
            'surcharge_percent' => 5,
        ];

        $result = $this->service->calculate($lines, $config);

        $this->assertEquals('25.00', $result['manifests']['M001']['manifest_fee']);
    }

    public function test_missing_surcharge_percent_defaults_to_default(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
        ];

        $config = [
            'manifest_fee' => 10.00,
        ];

        $result = $this->service->calculate($lines, $config);

        // 8.7% of (100 + 10) = 9.57
        $this->assertEquals('9.57', $result['manifests']['M001']['surcharge']);
    }

    public function test_amounts_as_strings(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => '100.50'],
            ['manifest_number' => 'M001', 'amount' => '50.25'],
        ];

        $config = [
            'manifest_fee' => '10.00',
            'surcharge_percent' => '5',
        ];

        $result = $this->service->calculate($lines, $config);

        $this->assertEquals('150.75', $result['manifests']['M001']['base_total']);
        $this->assertEquals('10.00', $result['manifests']['M001']['manifest_fee']);
        $this->assertEquals('160.75', $result['manifests']['M001']['subtotal']);
        $this->assertEquals('8.04', $result['manifests']['M001']['surcharge']);
        $this->assertEquals('168.79', $result['manifests']['M001']['manifest_total']);
    }

    public function test_amounts_as_integers(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100],
            ['manifest_number' => 'M001', 'amount' => 50],
        ];

        $config = [
            'manifest_fee' => 10,
            'surcharge_percent' => 5,
        ];

        $result = $this->service->calculate($lines, $config);

        $this->assertEquals('150.00', $result['manifests']['M001']['base_total']);
    }

    public function test_rounding_half_up(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
        ];

        $config = [
            'manifest_fee' => 0,
            'surcharge_percent' => 3.33,
        ];

        $result = $this->service->calculate($lines, $config);

        // 100 * 3.33% = 3.33, should round to 3.33
        $this->assertEquals('3.33', $result['manifests']['M001']['surcharge']);
    }

    public function test_rounding_half_up_with_fraction(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
        ];

        $config = [
            'manifest_fee' => 0,
            'surcharge_percent' => 1.005,
        ];

        $result = $this->service->calculate($lines, $config);

        // 100 * 1.005% = 1.005, should round to 1.01 (half up)
        $this->assertEquals('1.01', $result['manifests']['M001']['surcharge']);
    }

    public function test_negative_amounts(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
            ['manifest_number' => 'M001', 'amount' => -25.00],
        ];

        $config = [
            'manifest_fee' => 10.00,
            'surcharge_percent' => 5,
        ];

        $result = $this->service->calculate($lines, $config);

        $this->assertEquals('75.00', $result['manifests']['M001']['base_total']);
        $this->assertEquals('10.00', $result['manifests']['M001']['manifest_fee']);
        $this->assertEquals('85.00', $result['manifests']['M001']['subtotal']);
        $this->assertEquals('4.25', $result['manifests']['M001']['surcharge']);
        $this->assertEquals('89.25', $result['manifests']['M001']['manifest_total']);
    }

    public function test_preserves_manifest_order(): void
    {
        $lines = [
            ['manifest_number' => 'M003', 'amount' => 30.00],
            ['manifest_number' => 'M001', 'amount' => 10.00],
            ['manifest_number' => 'M002', 'amount' => 20.00],
            ['manifest_number' => 'M003', 'amount' => 5.00],
        ];

        $config = ['manifest_fee' => 0, 'surcharge_percent' => 0];

        $result = $this->service->calculate($lines, $config);

        $this->assertCount(3, $result['manifests']);
        $this->assertEquals('35.00', $result['manifests']['M003']['base_total']);
        $this->assertEquals('10.00', $result['manifests']['M001']['base_total']);
        $this->assertEquals('20.00', $result['manifests']['M002']['base_total']);
    }

    public function test_throws_exception_for_missing_manifest_number(): void
    {
        $lines = [
            ['amount' => 100.00],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice line at position 0 is missing a manifest_number.');

        $this->service->calculate($lines, []);
    }

    public function test_throws_exception_for_empty_manifest_number(): void
    {
        $lines = [
            ['manifest_number' => '', 'amount' => 100.00],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice line at position 0 is missing a manifest_number.');

        $this->service->calculate($lines, []);
    }

    public function test_throws_exception_for_missing_amount(): void
    {
        $lines = [
            ['manifest_number' => 'M001'],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice line at position 0 is missing an amount.');

        $this->service->calculate($lines, []);
    }

    public function test_throws_exception_for_non_numeric_amount(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 'invalid'],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be numeric');

        $this->service->calculate($lines, []);
    }

    public function test_throws_exception_for_invalid_surcharge_applies_to(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 100.00],
        ];

        $config = [
            'surcharge_applies_to' => 'invalid_mode',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported surcharge_applies_to 'invalid_mode'");

        $this->service->calculate($lines, $config);
    }

    public function test_empty_lines_returns_empty_manifests(): void
    {
        $lines = [];
        $config = ['manifest_fee' => 10.00, 'surcharge_percent' => 5];

        $result = $this->service->calculate($lines, $config);

        $this->assertEmpty($result['manifests']);
        $this->assertEquals('0.00', $result['invoice_total']);
    }

    public function test_decimal_with_trailing_zeros(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => '100.500'],
        ];

        $config = [
            'manifest_fee' => '10.000',
            'surcharge_percent' => '5.00',
        ];

        $result = $this->service->calculate($lines, $config);

        $this->assertEquals('100.50', $result['manifests']['M001']['base_total']);
        $this->assertEquals('10.00', $result['manifests']['M001']['manifest_fee']);
    }

    public function test_fractional_percent(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 1000.00],
        ];

        $config = [
            'manifest_fee' => 0,
            'surcharge_percent' => 2.5,
        ];

        $result = $this->service->calculate($lines, $config);

        $this->assertEquals('25.00', $result['manifests']['M001']['surcharge']);
    }

    public function test_large_amounts(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 999999.99],
        ];

        $config = [
            'manifest_fee' => 0,
            'surcharge_percent' => 10,
        ];

        $result = $this->service->calculate($lines, $config);

        $this->assertEquals('999999.99', $result['manifests']['M001']['base_total']);
        $this->assertEquals('100000.00', $result['manifests']['M001']['surcharge']);
        $this->assertEquals('1099999.99', $result['manifests']['M001']['manifest_total']);
    }

    public function test_manifest_order_preservation_with_duplicates(): void
    {
        $lines = [
            ['manifest_number' => 'M001', 'amount' => 10.00],
            ['manifest_number' => 'M002', 'amount' => 20.00],
            ['manifest_number' => 'M001', 'amount' => 5.00],
            ['manifest_number' => 'M003', 'amount' => 30.00],
            ['manifest_number' => 'M002', 'amount' => 10.00],
        ];

        $config = ['manifest_fee' => 0, 'surcharge_percent' => 0];

        $result = $this->service->calculate($lines, $config);

        $this->assertCount(3, $result['manifests']);
        $this->assertEquals('15.00', $result['manifests']['M001']['base_total']);
        $this->assertEquals('30.00', $result['manifests']['M002']['base_total']);
        $this->assertEquals('30.00', $result['manifests']['M003']['base_total']);
    }
}
