<?php

namespace App\Support\Presentation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

final class MealPlanDisplay
{
    public const WEEKDAYS = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Нд'];

    public static function day(int $number, int $start): string
    {
        if ($number < 1 || ! isset(self::WEEKDAYS[$start])) {
            throw new DomainException('Некоректний порядковий день або початок тижня.');
        }

        return 'День '.$number.' ('.self::WEEKDAYS[(($start + $number - 2) % 7) + 1].')';
    }

    public static function decimal(mixed $value, int $scale = 1): string
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $value)) {
            throw new DomainException('Некоректне decimal значення презентації.');
        }

        return (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function nutrition(array $values): string
    {
        try {
            return self::decimal($values['kcal'] ?? null, 0).' ккал · Б '.self::decimal($values['protein'] ?? null)
                .' · Ж '.self::decimal($values['fat'] ?? null).' · В '.self::decimal($values['carbs'] ?? null);
        } catch (DomainException) {
            return 'КБЖВ недоступні';
        }
    }

    public static function quantity(string $value): string
    {
        $number = self::decimal($value, 2);

        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
