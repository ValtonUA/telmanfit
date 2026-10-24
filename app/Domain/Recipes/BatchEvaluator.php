<?php

namespace App\Domain\Recipes;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Чиста функція: тільки прийняті snapshots, без I/O та фонових записів. */
final class BatchEvaluator
{
    public const NUTRIENTS = ['kcal', 'protein', 'fat', 'carbs'];

    public function evaluate(array $rows, array $pending = []): array
    {
        $totals = array_fill_keys(['single', 'week', 'half_week'], array_fill_keys(self::NUTRIENTS, '0.000000'));
        $result = [];
        foreach ($rows as $id => $row) {
            $a = $this->positive($row['quantity']);
            $nutrients = [];
            foreach (self::NUTRIENTS as $key) {
                $nutrients[$key] = $this->decimal($row['nutrients'][$key], 2, false);
            }
            $actual = ['single' => ['quantity' => $a, 'grams' => $a, 'uom' => 'g', 'factor' => '1.00']];
            foreach (['week', 'half_week'] as $code) {
                $parent = $actual[$code === 'week' ? 'single' : 'week']['grams'];
                $target = $code === 'week' ? BigDecimal::of($parent)->multipliedBy(7) : BigDecimal::of($parent)->dividedBy(2, 3, RoundingMode::Unnecessary);
                $state = $row[$code];
                if (! in_array($state['mode'], ['auto', 'manual'], true)) {
                    throw new InvalidArgumentException('Невідомий режим.');
                }
                $uom = $state['uom'];
                $factor = $uom === 'g' ? '1.00' : $this->positive($row['factors'][$uom] ?? throw new InvalidArgumentException('Немає підтвердженої конверсії.'));
                $policy = $state['policy'];
                $step = $this->positive($policy['step']);
                if (! in_array($policy['rounding'], ['exact', 'ceil', 'nearest'], true)
                    || ($policy['rounding'] === 'exact' && ($uom !== 'g' || $step !== '0.01'))
                    || ($policy['rounding'] !== 'exact' && ! ($policy['confirmed'] ?? false))) {
                    throw new InvalidArgumentException('Непогоджена policy.');
                }
                $quantity = $state['mode'] === 'manual'
                    ? $this->positive($state['quantity'])
                    : $this->automatic($target, $factor, $step, $policy['rounding']);
                $grams = $this->positive((string) BigDecimal::of($quantity)->multipliedBy($factor)->toScale(2, RoundingMode::HalfUp));
                // Зміна A позначає manual C навіть за незмінної manual B.
                $context = [$a, $parent, $nutrients, $row['ingredient_fingerprint'], $row['conversion_fingerprint'], $uom, $factor, $policy, $code === 'half_week' ? $actual['week']['quantity'] : $a, $code === 'half_week' ? $actual['week']['uom'] : 'g'];
                $review = $state['mode'] === 'manual' && ($state['acknowledged_context'] ?? null) !== $context;
                $actual[$code] = compact('quantity', 'grams', 'uom', 'factor', 'context', 'review') + ['mode' => $state['mode'], 'target_grams' => (string) $target];
            }
            foreach ($actual as $code => $quantity) {
                foreach ($nutrients as $key => $value) {
                    $total = BigDecimal::of($totals[$code][$key])->plus(BigDecimal::of($quantity['grams'])->multipliedBy($value)->dividedBy(100, 6, RoundingMode::Unnecessary));
                    $totals[$code][$key] = $this->decimal((string) $total, 6, false, '99999999.999999');
                }
            }
            $result[$id] = $actual;
        }
        // Сумісні A/1 поля baseline мають numeric(7,2), а не numeric(14,6).
        foreach ($totals['single'] as $value) {
            $this->decimal((string) BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp), 2, false);
        }
        $portions = [];
        foreach (['A/1' => ['single', 1], 'B/7' => ['week', 7], 'C/3' => ['half_week', 3], 'C/4' => ['half_week', 4]] as $label => [$code, $divisor]) {
            foreach ($totals[$code] as $key => $value) {
                $portions[$label][$key] = (string) BigDecimal::of($value)->dividedBy($divisor, 2, RoundingMode::HalfUp);
            }
        }

        return ['rows' => $result, 'totals' => $totals, 'portions' => $portions, 'incomplete' => $pending !== [] || $rows === []];
    }

    /** Явна дія; preview/reset не змінює persisted стан. */
    public function reset(array $state): array
    {
        $state['mode'] = 'auto';
        unset($state['quantity'], $state['acknowledged_context']);

        return $state;
    }

    private function automatic(BigDecimal $target, string $factor, string $step, string $rounding): string
    {
        // Ділення через rational зберігає точність перед ceil/nearest.
        $units = $target->toBigRational()->dividedBy($factor)->dividedBy($step);
        $quantity = $rounding === 'exact'
            ? $units->multipliedBy($step)->toScale(2, RoundingMode::HalfUp)
            : $units->toScale(0, $rounding === 'ceil' ? RoundingMode::Ceiling : RoundingMode::HalfUp)->multipliedBy($step);

        return $this->positive((string) $quantity);
    }

    private function positive(string $value): string
    {
        return $this->decimal($value, 2, true);
    }

    private function decimal(string $value, int $scale, bool $positive, string $max = '99999.99'): string
    {
        if (! preg_match('/^\d+(\.\d+)?$/D', $value)) {
            throw new InvalidArgumentException('Потрібний скінченний decimal string.');
        }
        $decimal = BigDecimal::of($value);
        if (($positive ? $decimal->isLessThanOrEqualTo(0) : $decimal->isLessThan(0)) || $decimal->isGreaterThan($max)) {
            throw new InvalidArgumentException('Decimal поза діапазоном.');
        }
        try {
            return (string) $decimal->toScale($scale, RoundingMode::Unnecessary);
        } catch (\ArithmeticError|MathException $e) {
            throw new InvalidArgumentException('Надлишкова точність.', previous: $e);
        }
    }
}
