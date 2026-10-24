<?php

namespace Tests\Recipes;

use Tests\Unit\BatchEvaluatorTest;

/** Спільні vectors для PHP і PG; усі конверсії синтетичні. */
final class InheritedUomCases
{
    public static function cases(): array
    {
        return [
            'pieces' => ['piece', '3.00', '100.00', '1.00', '2.00', '200.00'],
            'half-can' => ['test-can', '1.00', '420.00', '0.50', '0.50', '210.00'],
            'whole-can-only' => ['test-can', '1.00', '420.00', '1.00', '1.00', '420.00'],
        ];
    }

    public static function row(string $uom, string $quantity, string $factor, string $step): array
    {
        $row = BatchEvaluatorTest::row();
        // B manual може мати старий exact-g preset; C має власне підтверджене правило UOM.
        $row['week'] = ['mode' => 'manual', 'uom' => $uom, 'quantity' => $quantity, 'policy' => $row['week']['policy']];
        $row['factors'][$uom] = $factor;
        $row['auto_policies'][$uom] = ['rounding' => 'ceil', 'step' => $step, 'confirmed' => true];

        return $row;
    }
}
