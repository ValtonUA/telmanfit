<?php

namespace App\Domain\MealPlans;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

/** Точна ідентичність порції; nutrition — decimal strings scale 6. */
final readonly class MealItemPortion
{
    public function __construct(
        public int $foodId,
        public MealItemKind $kind,
        public ?int $recipeVersionId,
        public ?int $formatId,
        public ?int $divisor,
        public string $quantity,
        public array $nutrition,
    ) {}

    /** Спочатку множення, потім одне округлення: проміжна порція не округлюється. */
    public static function nutrition(array $totals, string $quantity, int $divisor): array
    {
        if ($divisor <= 0 || ! preg_match('/^\d+(\.\d+)?$/D', $quantity) || BigDecimal::of($quantity)->isLessThanOrEqualTo(0)) {
            throw new DomainException('Некоректна кількість споживання.');
        }
        $result = [];
        foreach (['kcal', 'protein', 'fat', 'carbs'] as $key) {
            $value = $totals[$key] ?? null;
            if (! is_string($value) || ! preg_match('/^\d+(\.\d+)?$/D', $value)) {
                throw new DomainException('Відсутній або некоректний frozen nutrient.');
            }
            $result[$key] = (string) BigDecimal::of($value)->multipliedBy($quantity)->dividedBy($divisor, 6, RoundingMode::HalfUp);
        }

        return $result;
    }
}
