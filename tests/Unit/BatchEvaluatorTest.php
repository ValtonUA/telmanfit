<?php

namespace Tests\Unit;

use App\Domain\Recipes\BatchEvaluator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BatchEvaluatorTest extends TestCase
{
    public static function row(): array
    {
        $state = ['mode' => 'auto', 'uom' => 'g', 'policy' => ['rounding' => 'exact', 'step' => '0.01']];

        return ['quantity' => '150.00', 'nutrients' => ['kcal' => '123.45', 'protein' => '20.00', 'fat' => '3.00', 'carbs' => '4.00'],
            'ingredient_fingerprint' => 'ingredient-1', 'conversion_fingerprint' => 'conversion-1', 'factors' => [], 'week' => $state, 'half_week' => $state];
    }

    public function test_graph_manual_ack_reload_parent_change_and_reset(): void
    {
        $e = new BatchEvaluator;
        $row = self::row();
        $out = $e->evaluate([42 => $row]);
        $this->assertSame('1050.00', $out['rows'][42]['week']['grams']);
        $this->assertSame('525.00', $out['rows'][42]['half_week']['grams']);
        $row['week']['mode'] = 'manual';
        $row['week']['quantity'] = '1000.00';
        $this->assertSame('500.00', $e->evaluate([42 => $row])['rows'][42]['half_week']['grams']);
        $row['half_week']['mode'] = 'manual';
        $row['half_week']['quantity'] = '600.00';
        foreach (['week', 'half_week'] as $code) {
            $row[$code]['acknowledged_context'] = $e->evaluate([42 => $row])['rows'][42][$code]['context'];
        }
        $saved = json_decode(json_encode($row), true);
        $this->assertSame($e->evaluate([42 => $row]), $e->evaluate([42 => $saved]));
        $this->assertFalse($e->evaluate([42 => $saved])['rows'][42]['half_week']['review']);
        $saved['quantity'] = '151.00';
        $out = $e->evaluate([42 => $saved]);
        foreach (['week' => '1000.00', 'half_week' => '600.00'] as $code => $grams) {
            $this->assertSame($grams, $out['rows'][42][$code]['grams']);
            $this->assertTrue($out['rows'][42][$code]['review']);
        }
        $saved['week'] = $e->reset($saved['week']);
        $this->assertSame('1057.00', $e->evaluate([42 => $saved])['rows'][42]['week']['grams']);
        $this->assertSame('600.00', $e->evaluate([42 => $saved])['rows'][42]['half_week']['grams']);
        $saved['half_week'] = $e->reset($saved['half_week']);
        $this->assertSame('528.50', $e->evaluate([42 => $saved])['rows'][42]['half_week']['grams']);
    }

    public function test_conversion_rounding_portions_and_pending_are_exact(): void
    {
        $row = self::row();
        $row['week'] = ['mode' => 'manual', 'quantity' => '0.25', 'uom' => 'test-can', 'policy' => ['rounding' => 'nearest', 'step' => '0.25', 'confirmed' => true]];
        $row['factors']['test-can'] = '33.33';
        $out = (new BatchEvaluator)->evaluate([1 => $row], [['id' => 'pending:test']]);
        $this->assertSame('8.33', $out['rows'][1]['week']['grams']);
        $this->assertSame('4.17', $out['rows'][1]['half_week']['grams']);
        $this->assertSame('0.25', $out['rows'][1]['week']['quantity']);
        $this->assertSame('test-can', $out['rows'][1]['week']['uom']);
        $this->assertSame('5.147865', $out['totals']['half_week']['kcal']);
        $this->assertSame('1.72', $out['portions']['C/3']['kcal']);
        $this->assertSame('1.29', $out['portions']['C/4']['kcal']);
        $this->assertTrue($out['incomplete']);
    }

    public function test_count_ceil_opt_in_and_nearest_tie_up(): void
    {
        $row = self::row();
        $row['quantity'] = '15.00';
        $row['week'] = ['mode' => 'auto', 'uom' => 'piece', 'policy' => ['rounding' => 'ceil', 'step' => '1.00', 'confirmed' => true]];
        $row['factors']['piece'] = '100.00';
        $this->assertSame('2.00', (new BatchEvaluator)->evaluate([1 => $row])['rows'][1]['week']['quantity']);
        $row['quantity'] = '150.00';
        $row['week'] = ['mode' => 'auto', 'uom' => 'g', 'policy' => ['rounding' => 'nearest', 'step' => '100.00', 'confirmed' => true]];
        $this->assertSame('1100.00', (new BatchEvaluator)->evaluate([1 => $row])['rows'][1]['week']['grams']);
    }

    public function test_equal_manual_stays_manual_and_fingerprint_change_needs_review(): void
    {
        $row = self::row();
        $row['week']['mode'] = 'manual';
        $row['week']['quantity'] = '1050.00';
        $e = new BatchEvaluator;
        $row['week']['acknowledged_context'] = $e->evaluate([1 => $row])['rows'][1]['week']['context'];
        $row['ingredient_fingerprint'] = 'changed';
        $out = $e->evaluate([1 => $row]);
        $this->assertSame('manual', $out['rows'][1]['week']['mode']);
        $this->assertTrue($out['rows'][1]['week']['review']);
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_precision_nonfinite_and_overflow_fail(string $quantity): void
    {
        $row = self::row();
        $row['quantity'] = $quantity;
        $this->expectException(InvalidArgumentException::class);
        (new BatchEvaluator)->evaluate([1 => $row]);
    }

    public static function invalidQuantities(): array
    {
        return array_map(fn ($v) => [$v], ['0', '-1', 'NaN', 'INF', '1e3', '0.005', '100000', '99999.99']);
    }

    public function test_a_compatibility_overflow_fails_before_storage(): void
    {
        $row = self::row();
        $row['quantity'] = '10000.00';
        $row['nutrients']['kcal'] = '99999.99';
        $this->expectException(InvalidArgumentException::class);
        (new BatchEvaluator)->evaluate([1 => $row]);
    }

    public function test_missing_conversion_is_not_invented(): void
    {
        $row = self::row();
        $row['week']['uom'] = 'unknown-can';
        $this->expectException(InvalidArgumentException::class);
        (new BatchEvaluator)->evaluate([1 => $row]);
    }
}
