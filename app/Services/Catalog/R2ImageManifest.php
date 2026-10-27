<?php

namespace App\Services\Catalog;

use App\Support\Presentation\PublicImageUrl;
use RuntimeException;

final class R2ImageManifest
{
    public function __construct(private array $manifest) {}

    public static function load(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Manifest недоступний.');
        }
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new RuntimeException('Некоректний manifest.');
        }

        return new self($manifest);
    }

    public function verify(array $plan, string $baseUrl): void
    {
        $expected = $this->manifest['updates'] ?? null;
        if (! is_array($expected) || ! array_is_list($expected) || $expected === []
            || ($this->manifest['expected_updates'] ?? null) !== count($expected)
            || ($this->manifest['public_base_url'] ?? null) !== $baseUrl
            || PublicImageUrl::safe($baseUrl) === null) {
            throw new RuntimeException('Некоректний manifest або public base URL.');
        }
        $approved = [];
        $keys = [];
        foreach ($expected as $row) {
            if (! is_array($row) || ! in_array($row['table'] ?? null, ['ingredients', 'kitchen_equipment', 'recipes'], true)
                || ! is_int($row['id'] ?? null) || $row['id'] < 1 || ! is_string($row['name'] ?? null)
                || ! is_string($row['r2_key'] ?? null) || ! is_string($row['proposed_image_url'] ?? null)
                || ! array_key_exists('before_image_url', $row) || $row['before_image_url'] !== null) {
                throw new RuntimeException('Некоректний запис manifest.');
            }
            $target = $row['table'].':'.$row['id'];
            $url = $baseUrl.'/'.implode('/', array_map('rawurlencode', explode('/', $row['r2_key'])));
            if (isset($approved[$target]) || isset($keys[$row['r2_key']])
                || ! str_starts_with($row['r2_key'], $row['table'].'/')
                || $url !== $row['proposed_image_url'] || PublicImageUrl::safe($url) !== $url) {
                throw new RuntimeException('Дублікат або змінений URL/key у manifest.');
            }
            $approved[$target] = [$row['table'], $row['id'], $row['name'], $row['r2_key'], $url];
            $keys[$row['r2_key']] = true;
        }
        $actual = [];
        $seenKeys = [];
        foreach ($plan as $row) {
            if (isset($seenKeys[$row['key']])) {
                throw new RuntimeException('Дублікат R2 key.');
            }
            $seenKeys[$row['key']] = true;
            if ($row['status'] !== 'confident') {
                continue;
            }
            $record = $row['record'];
            $target = $row['type'].':'.$record['id'];
            $url = $baseUrl.'/'.implode('/', array_map('rawurlencode', explode('/', $row['key'])));
            if (isset($actual[$target]) || ($record['image_url'] !== null && $record['image_url'] !== $url)) {
                throw new RuntimeException('Дублікат цілі або наявний інший image_url.');
            }
            $actual[$target] = [$row['type'], $record['id'], $record['name'], $row['key'], $url];
        }
        ksort($approved);
        ksort($actual);
        if ($approved !== $actual) {
            throw new RuntimeException('Manifest mismatch: набір table/ID/name/key/URL відрізняється від затвердженого.');
        }
    }
}
