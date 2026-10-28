<?php

namespace App\Services\Catalog;

use Illuminate\Support\Str;

final class R2ImageMatcher
{
    /** @param list<array{key: string}> $objects @param array<string, list<array{id: int, name: string, image_url: ?string}>> $records @param array<string, array<string, list<string>>> $aliases */
    public function plan(array $objects, array $records, array $aliases = [], bool $exactOnly = false): array
    {
        $plan = [];

        foreach ($objects as $object) {
            $key = $object['key'];
            [$type, $basename] = array_pad(explode('/', $key, 2), 2, '');
            if ($type === 'equipment') {
                $plan[] = ['key' => $key, 'type' => $type, 'status' => 'ignored', 'suggestions' => [], 'reason' => 'Канонічна папка обладнання — kitchen_equipment/; legacy equipment/ пропущено.'];

                continue;
            }
            $filename = pathinfo($basename, PATHINFO_FILENAME);
            $needle = self::normalize($filename);

            if (! in_array($type, ['ingredients', 'kitchen_equipment', 'recipes'], true) || $needle === '') {
                $plan[] = ['key' => $key, 'type' => $type, 'status' => 'unsupported', 'suggestions' => []];

                continue;
            }

            $table = $type === 'ingredients' ? 'ingredients' : ($type === 'recipes' ? 'recipes' : 'kitchen_equipment');
            $candidates = [];
            foreach ($records[$type] ?? [] as $record) {
                $names = [$record['name'], ...($aliases[$table][(string) $record['id']] ?? [])];
                $normalized = array_unique(array_filter(array_map(self::normalize(...), $names)));
                $exact = in_array($needle, $normalized, true);
                $score = $exact ? 1.0 : max(array_map(fn (string $name): float => self::similarity($needle, $name), $normalized ?: ['']));

                if ($exact || (! $exactOnly && $score >= 0.48)) {
                    $candidates[] = [
                        'id' => (int) $record['id'],
                        'name' => $record['name'],
                        'image_url' => $record['image_url'],
                        'exact' => $exact,
                        'score' => $score,
                    ];
                }
            }

            $exact = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['exact']));
            $suggestions = $exact !== [] ? $exact : $candidates;
            usort($suggestions, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            $plan[] = [
                'key' => $key,
                'type' => $type,
                'status' => count($exact) === 1 ? 'confident' : (count($exact) > 1 || $suggestions !== [] ? 'ambiguous' : 'unmatched'),
                'record' => count($exact) === 1 ? $exact[0] : null,
                'suggestions' => $suggestions,
            ];
        }

        $byRecord = [];
        foreach ($plan as $index => $entry) {
            if ($entry['status'] === 'confident') {
                $candidate = $entry['record'];
                $byRecord[$entry['type'].':'.$candidate['id']][] = $index;
            }
        }

        foreach ($byRecord as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            foreach ($indexes as $index) {
                $candidate = $plan[$index]['record'];
                $plan[$index]['status'] = 'ambiguous';
                $plan[$index]['suggestions'] = [$candidate];
                $plan[$index]['record'] = null;
                $plan[$index]['reason'] = 'Кілька об’єктів відповідають одному запису.';
            }
        }

        return $plan;
    }

    public static function normalize(string $value): string
    {
        return Str::slug(pathinfo($value, PATHINFO_FILENAME), '_');
    }

    private static function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        return 1 - (levenshtein($left, $right) / max(strlen($left), strlen($right)));
    }
}
