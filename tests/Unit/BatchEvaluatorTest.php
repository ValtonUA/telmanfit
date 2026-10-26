<?php

namespace Tests\Unit;

use App\Domain\Recipes\BatchEvaluator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Recipes\InheritedUomCases;

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
        $saved['week'] = $e->reset($saved['week'], self::row()['week']);
        $this->assertSame('1057.00', $e->evaluate([42 => $saved])['rows'][42]['week']['grams']);
        $this->assertSame('600.00', $e->evaluate([42 => $saved])['rows'][42]['half_week']['grams']);
        $saved['half_week'] = $e->reset($saved['half_week'], self::row()['half_week']);
        $this->assertSame('528.50', $e->evaluate([42 => $saved])['rows'][42]['half_week']['grams']);
    }

    public function test_conversion_rounding_portions_and_pending_are_exact(): void
    {
        $row = self::row();
        $row['week'] = ['mode' => 'manual', 'quantity' => '0.25', 'uom' => 'test-can', 'policy' => ['rounding' => 'nearest', 'step' => '0.25', 'confirmed' => true]];
        $row['factors']['test-can'] = '33.33';
        $row['half_week']['mode'] = 'manual';
        $row['half_week']['quantity'] = '4.17';
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
        $row['auto_policies']['piece'] = $row['week']['policy'];
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

    public function test_manual_uom_ignores_auto_preset_and_reset_uses_accepted_automatic_uom(): void
    {
        $row = self::row();
        $row['week']['mode'] = 'manual';
        $row['week']['quantity'] = '1.00';
        $row['week']['uom'] = 'test-can';
        $row['factors']['test-can'] = '420.00';
        $row['auto_policies']['test-can'] = ['rounding' => 'ceil', 'step' => '0.50', 'confirmed' => true];
        // Старий exact-g preset не забороняє авторську ручну банку.
        $e = new BatchEvaluator;
        $out = $e->evaluate([1 => $row]);
        $this->assertSame('420.00', $out['rows'][1]['week']['grams']);
        $this->assertSame('210.00', $out['rows'][1]['half_week']['grams']);
        $row['week'] = $e->reset($row['week'], self::row()['week']);
        $out = $e->evaluate([1 => $row]);
        $this->assertSame('g', $out['rows'][1]['week']['uom']);
        $this->assertSame('1050.00', $out['rows'][1]['week']['grams']);
    }

    #[DataProvider('inheritedCases')]
    public function test_auto_c_inherits_actual_b_uom_and_policy_after_serialization(string $uom, string $quantity, string $factor, string $step, string $expected, string $grams): void
    {
        $row = InheritedUomCases::row($uom, $quantity, $factor, $step);
        $e = new BatchEvaluator;
        $out = $e->evaluate([42 => $row]);
        $c = $out['rows'][42]['half_week'];
        $this->assertSame('auto', $c['mode']);
        $this->assertSame($uom, $c['uom']);
        $this->assertSame($expected, $c['quantity']);
        $this->assertSame($grams, $c['grams']);
        // Hydration повертає canonical C, а не викликає user-change/manual.
        $row['half_week'] = $c;
        $this->assertSame($out, $e->evaluate([42 => json_decode(json_encode($row), true)]));
    }

    public static function inheritedCases(): array
    {
        return InheritedUomCases::cases();
    }

    public function test_b_uom_changes_rebuild_auto_c_but_preserve_manual_c_and_ack(): void
    {
        $e = new BatchEvaluator;
        $row = InheritedUomCases::row('piece', '3.00', '100.00', '1.00');
        $row['half_week'] = $e->evaluate([1 => $row])['rows'][1]['half_week'];
        $this->assertSame('piece', $row['half_week']['uom']);
        $row['week']['uom'] = 'test-can';
        $row['week']['quantity'] = '1.00';
        $row['factors']['test-can'] = '420.00';
        $row['auto_policies']['test-can'] = ['rounding' => 'ceil', 'step' => '0.50', 'confirmed' => true];
        $out = $e->evaluate([1 => $row]);
        $this->assertSame('test-can', $out['rows'][1]['half_week']['uom']);
        $this->assertSame('0.50', $out['rows'][1]['half_week']['quantity']);
        $row['half_week'] = $out['rows'][1]['half_week'];
        $row['half_week']['mode'] = 'manual';
        $row['half_week']['acknowledged_context'] = $e->evaluate([1 => $row])['rows'][1]['half_week']['context'];
        $row['week']['uom'] = 'g';
        $row['week']['quantity'] = '1000.00';
        $row['half_week']['acknowledged_context'] = json_decode(json_encode($row['half_week']['acknowledged_context']), true);
        $out = $e->evaluate([1 => $row]);
        $this->assertSame('manual', $out['rows'][1]['half_week']['mode']);
        $this->assertSame('test-can', $out['rows'][1]['half_week']['uom']);
        $this->assertSame('0.50', $out['rows'][1]['half_week']['quantity']);
        $this->assertTrue($out['rows'][1]['half_week']['review']);
        $row['half_week'] = $e->reset($row['half_week'], self::row()['half_week']);
        $out = $e->evaluate([1 => $row]);
        $this->assertSame('g', $out['rows'][1]['half_week']['uom']);
        $this->assertSame('500.00', $out['rows'][1]['half_week']['quantity']);
    }

    #[DataProvider('invalidInheritedPolicies')]
    public function test_auto_c_rejects_missing_unconfirmed_or_invalid_inherited_policy(array $policies): void
    {
        $row = InheritedUomCases::row('piece', '3.00', '100.00', '1.00');
        $row['auto_policies'] = $policies;
        // Старий валідний g preset C не дозволяє непомітний fallback у грами.
        $this->expectException(InvalidArgumentException::class);
        (new BatchEvaluator)->evaluate([1 => $row]);
    }

    public static function invalidInheritedPolicies(): array
    {
        return [
            'missing' => [[]],
            'missing-step' => [['piece' => ['rounding' => 'ceil', 'confirmed' => true]]],
            'noncanonical-step' => [['piece' => ['rounding' => 'ceil', 'step' => '1', 'confirmed' => true]]],
            'other-uom' => [['test-can' => ['rounding' => 'ceil', 'step' => '0.50', 'confirmed' => true]]],
            'unconfirmed' => [['piece' => ['rounding' => 'ceil', 'step' => '1.00', 'confirmed' => false]]],
            'exact-for-count' => [['piece' => ['rounding' => 'exact', 'step' => '0.01']]],
            'zero-step' => [['piece' => ['rounding' => 'ceil', 'step' => '0.00', 'confirmed' => true]]],
            'unknown-rounding' => [['piece' => ['rounding' => 'unknown', 'step' => '1.00', 'confirmed' => true]]],
        ];
    }

    public function test_manual_ack_survives_jsonb_object_key_reordering(): void
    {
        $e = new BatchEvaluator;
        $row = InheritedUomCases::row('piece', '3.00', '100.00', '1.00');
        $row['half_week'] = $e->evaluate([1 => $row])['rows'][1]['half_week'];
        $row['half_week']['mode'] = 'manual';
        $context = $e->evaluate([1 => $row])['rows'][1]['half_week']['context'];
        $row['half_week']['acknowledged_context'] = $context;
        krsort($row['half_week']['policy']);
        krsort($row['half_week']['acknowledged_context'][2]);
        krsort($row['half_week']['acknowledged_context'][7]);
        $this->assertFalse($e->evaluate([1 => $row])['rows'][1]['half_week']['review']);
        $this->assertSame($context, $e->evaluate([1 => $row])['rows'][1]['half_week']['context']);
    }

    public function test_explicit_invalid_gram_policy_does_not_silently_fallback(): void
    {
        $row = self::row();
        $row['auto_policies'] = ['g' => null];
        $this->expectException(InvalidArgumentException::class);
        (new BatchEvaluator)->evaluate([1 => $row]);
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
