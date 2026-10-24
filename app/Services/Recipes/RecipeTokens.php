<?php

namespace App\Services\Recipes;

use Illuminate\Validation\ValidationException;

final class RecipeTokens
{
    private const PATTERN = '/\{\{(ingredient|equipment):([a-zA-Z0-9:-]+):([a-z_]+)\}\}/';

    public static function remap(string $text, array $map, array $equipmentMap = []): string
    {
        return preg_replace_callback(self::PATTERN, function ($m) use ($map, $equipmentMap) {
            $ids = $m[1] === 'ingredient' ? $map : $equipmentMap;

            return '{{'.$m[1].':'.($ids[$m[2]] ?? $m[2]).':'.$m[3].'}}';
        }, $text);
    }

    /** Повертає plain text: Blade обов’язково екранує його. */
    public static function render(string $text, array $rows, string $code, array $equipment = []): string
    {
        $result = preg_replace_callback(self::PATTERN, function ($m) use ($rows, $code, $equipment) {
            $q = ($m[1] === 'ingredient' ? $rows : $equipment)[$m[2]][$code] ?? null;
            if (! $q) {
                throw ValidationException::withMessages(['batch' => 'Токен посилається на незавершений або видалений рядок: '.$m[2]]);
            }
            if ($m[1] === 'ingredient') {
                return match ($m[3]) {
                    'grams' => $q['grams'].' g',
                    'quantity' => $q['quantity'].' '.$q['uom'],
                    default => throw ValidationException::withMessages(['batch' => 'Невідома характеристика інгредієнта.']),
                };
            }

            return match ($m[3]) {
                'quantity' => $q['min_value'] === null ? 'Без числової вимоги' : trim($q['min_value'].' '.$q['uom']),
                'min_value' => $q['min_value'] ?? 'Без числової вимоги',
                'uom', 'tip', 'name' => $q[$m[3]],
                default => throw ValidationException::withMessages(['batch' => 'Невідома характеристика обладнання.']),
            };
        }, $text);
        if (str_contains($result, '{{') || str_contains($result, '}}')) {
            throw ValidationException::withMessages(['batch' => 'Невідомий або пошкоджений токен інструкції.']);
        }

        return $result;
    }
}
