<?php

namespace App\Console\Commands;

use App\Services\Catalog\R2ImageMatcher;
use App\Services\Catalog\R2ObjectSource;
use App\Support\Presentation\PublicImageUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class MatchR2Images extends Command
{
    protected $signature = 'assets:match-r2-images
        {--dry-run : Лише прочитати R2 і PostgreSQL та показати план}
        {--apply : Записати лише однозначні відповідності з image_url IS NULL}
        {--public-base-url= : Публічний HTTPS base URL для застосування}
        {--aliases-file= : JSON зі синонімами й історичними кодами, прив’язаними до типу та ID запису}';

    protected $description = 'Зіставити публічні зображення Cloudflare R2 з каталогами без зміни бізнес-даних';

    public function handle(R2ObjectSource $lister, R2ImageMatcher $matcher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($dryRun === $apply) {
            $this->error('Вкажіть рівно один режим: --dry-run або --apply.');

            return self::INVALID;
        }

        try {
            $objects = $lister->list();
            $records = $this->records();
            $aliases = $this->aliases();
            $plan = $matcher->plan($objects, $records, $aliases);
            $this->markConflicts($plan);

            if ($apply) {
                $baseUrl = rtrim((string) $this->option('public-base-url'), '/');
                if ($baseUrl === '') {
                    throw new RuntimeException('--public-base-url обов’язковий для --apply.');
                }
                $this->apply($plan, $baseUrl);
            }

            $this->render($plan, $apply);
            $this->line('R2 objects: '.count($objects).'; confident: '.count(array_filter($plan, fn (array $row): bool => $row['status'] === 'confident')).'; ambiguous: '.count(array_filter($plan, fn (array $row): bool => $row['status'] === 'ambiguous')).'; unmatched: '.count(array_filter($plan, fn (array $row): bool => $row['status'] === 'unmatched')).'; unsupported: '.count(array_filter($plan, fn (array $row): bool => $row['status'] === 'unsupported')).'.');

            return self::SUCCESS;
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function records(): array
    {
        return [
            'ingredients' => DB::table('ingredients')->orderBy('id')->get(['id', 'name', 'image_url'])->map(fn ($row): array => (array) $row)->all(),
            'kitchen_equipment' => DB::table('kitchen_equipment')->orderBy('id')->get(['id', 'name', 'image_url'])->map(fn ($row): array => (array) $row)->all(),
            'recipes' => DB::table('recipes')->orderBy('id')->get(['id', 'title as name', 'image_url'])->map(fn ($row): array => (array) $row)->all(),
        ];
    }

    /** @throws JsonException */
    private function aliases(): array
    {
        $path = (string) $this->option('aliases-file');
        if ($path === '') {
            return [];
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Файл aliases не існує або недоступний для читання.');
        }

        $aliases = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($aliases)) {
            throw new RuntimeException('Aliases JSON має бути об’єктом типу → ID → список назв.');
        }

        foreach ($aliases as $table => $rows) {
            if (! in_array($table, ['ingredients', 'kitchen_equipment', 'recipes'], true) || ! is_array($rows)) {
                throw new RuntimeException('Aliases JSON має містити лише ingredients, kitchen_equipment і recipes.');
            }
            foreach ($rows as $id => $names) {
                if (! ctype_digit((string) $id) || ! is_array($names) || array_filter($names, fn ($name): bool => ! is_string($name)) !== []) {
                    throw new RuntimeException('Кожне значення aliases має бути списком рядків за числовим ID.');
                }
            }
        }

        return $aliases;
    }

    private function markConflicts(array &$plan): void
    {
        $targets = [];
        foreach ($plan as $index => $row) {
            if ($row['status'] === 'confident') {
                $targets[$row['type'].':'.$row['record']['id']][] = $index;
            }
        }

        foreach ($targets as $indexes) {
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
    }

    private function apply(array $plan, string $baseUrl): void
    {
        $baseParts = parse_url($baseUrl);
        if (! is_array($baseParts) || ($baseParts['scheme'] ?? '') !== 'https' || empty($baseParts['host'])
            || isset($baseParts['user']) || isset($baseParts['pass']) || isset($baseParts['query']) || isset($baseParts['fragment']) || isset($baseParts['port'])) {
            throw new RuntimeException('--public-base-url має бути HTTPS URL без credentials, query, fragment або port.');
        }

        $updates = [];
        foreach ($plan as $row) {
            if ($row['status'] !== 'confident') {
                continue;
            }

            $segments = array_map('rawurlencode', explode('/', $row['key']));
            $url = $baseUrl.'/'.implode('/', $segments);
            if (PublicImageUrl::safe($url) === null) {
                throw new RuntimeException('Base URL або згенероване посилання не проходить чинну HTTPS image allowlist.');
            }

            $updates[] = ['row' => $row, 'url' => $url];
        }

        DB::transaction(function () use ($updates): void {
            foreach ($updates as $update) {
                $row = $update['row'];
                $url = $update['url'];
                $table = $row['type'] === 'kitchen_equipment' ? 'kitchen_equipment' : $row['type'];
                $record = $row['record'];
                if ($record['image_url'] === $url) {
                    $this->line('UNCHANGED '.$row['key'].' → '.$table.'#'.$record['id']);

                    continue;
                }
                if ($record['image_url'] !== null && $record['image_url'] !== '') {
                    $this->warn('CONFLICT existing image_url: '.$row['key'].' → '.$table.'#'.$record['id']);

                    continue;
                }

                $updated = DB::table($table)->where('id', $record['id'])->whereNull('image_url')->update(['image_url' => $url]);
                if ($updated !== 1) {
                    $this->warn('CONFLICT concurrent or non-empty image_url: '.$row['key'].' → '.$table.'#'.$record['id']);
                } else {
                    $this->line('APPLIED '.$row['key'].' → '.$table.'#'.$record['id']);
                }
            }
        });
    }

    private function render(array $plan, bool $apply): void
    {
        $this->newLine();
        $this->line('| R2 key | Status | Target / suggestions | Existing image |');
        $this->line('|---|---|---|---|');
        foreach ($plan as $row) {
            $candidates = $row['status'] === 'confident' ? [$row['record']] : $row['suggestions'];
            $targets = implode('<br>', array_map(fn (?array $candidate): string => $candidate === null ? '' : '#'.$candidate['id'].' '.$candidate['name'], $candidates));
            $existing = implode('<br>', array_unique(array_filter(array_map(fn (?array $candidate): ?string => $candidate['image_url'] ?? null, $candidates))));
            $this->line('| `'.$row['key'].'` | '.$row['status'].' | '.str_replace('|', '\|', $targets ?: ($row['reason'] ?? '—')).' | '.str_replace('|', '\|', $existing ?: '—').' |');
        }
        if (! $apply) {
            $this->comment('Dry-run: PostgreSQL не змінювалася.');
        }
    }
}
