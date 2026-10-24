<?php

namespace App\Services\Recipes;

use Illuminate\Validation\ValidationException;

final class RecipeTokens
{
    private const PATTERN = '/\{\{ingredient:([a-zA-Z0-9:-]+):(quantity|grams)\}\}/';

    public static function remap(string $text, array $map): string
    {
        return preg_replace_callback(self::PATTERN, fn ($m) => '{{ingredient:'.($map[$m[1]] ?? $m[1]).':'.$m[2].'}}', $text);
    }

    /** Повертає plain text: Blade обов’язково екранує його. */
    public static function render(string $text, array $rows, string $code): string
    {
        $result = preg_replace_callback(self::PATTERN, function ($m) use ($rows, $code) {
            if (! isset($rows[$m[1]][$code])) {
                throw ValidationException::withMessages(['batch' => 'Токен посилається на незавершений або видалений рядок: '.$m[1]]);
            }
            $q = $rows[$m[1]][$code];

            return $m[2] === 'grams' ? $q['grams'].' g' : $q['quantity'].' '.$q['uom'];
        }, $text);
        if (str_contains($result, '{{') || str_contains($result, '}}')) {
            throw ValidationException::withMessages(['batch' => 'Невідомий токен інструкції.']);
        }

        return $result;
    }
}
