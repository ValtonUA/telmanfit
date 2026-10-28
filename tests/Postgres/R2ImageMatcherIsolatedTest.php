<?php

namespace Tests\Postgres;

use App\Services\Catalog\R2ObjectSource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Три тимчасові synthetic таблиці; жодних міграцій або seeders.
class R2ImageMatcherIsolatedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('TELMANFIT_PG_TEST') !== '1'
            || ! preg_match('~^/tmp/telmanfit-stage1\.[a-zA-Z0-9]+/socket$~', (string) getenv('DB_HOST'))
            || getenv('DB_DATABASE') !== 'telmanfit_stage1_test'
            || getenv('DB_USERNAME') !== 'telmanfit_test_runner') {
            throw new \RuntimeException('Потрібна ізольована synthetic PostgreSQL.');
        }
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame(18, intdiv((int) DB::selectOne('SHOW server_version_num')->server_version_num, 10000));
        $this->assertSame('telmanfit_stage1_test', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame('telmanfit_test_runner', DB::selectOne('SELECT current_user AS name')->name);
        $this->assertFalse(DB::selectOne('SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls AS privileged FROM pg_roles WHERE rolname=current_user')->privileged);
        DB::beginTransaction();
        DB::statement('CREATE TEMP TABLE ingredients (id integer PRIMARY KEY, name text, image_url text)');
        DB::statement('CREATE TEMP TABLE kitchen_equipment (id integer PRIMARY KEY, name text, image_url text)');
        DB::statement('CREATE TEMP TABLE recipes (id integer PRIMARY KEY, title text, image_url text)');
        config(['presentation.image_hosts' => ['images.example.com']]);
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        parent::tearDown();
    }

    private function source(array $keys): void
    {
        app()->instance(R2ObjectSource::class, new class($keys) implements R2ObjectSource
        {
            public function __construct(private array $keys) {}

            public function list(): array
            {
                return array_map(fn ($key) => ['key' => $key], $this->keys);
            }
        });
    }

    private function snapshot(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['ingredients', 'kitchen_equipment', 'recipes']);
    }

    private array $manifest;

    private function fixture(): array
    {
        $this->manifest = json_decode(file_get_contents(base_path('docs/assets/r2-local-image-plan.json')), true, 512, JSON_THROW_ON_ERROR);
        config(['presentation.image_hosts' => ['assets.telmanfit.com.ua']]);
        foreach ($this->manifest['updates'] as $row) {
            DB::table($row['table'])->insert(['id' => $row['id'], $row['table'] === 'recipes' ? 'title' : 'name' => $row['name'], 'image_url' => null]);
        }
        $keys = array_column($this->manifest['updates'], 'r2_key');
        $this->source([...$keys, 'equipment/oven.webp']);

        return ['--apply' => true, '--exact-only' => true,
            '--aliases-file' => base_path('docs/assets/r2-local-image-aliases.json'),
            '--public-base-url' => $this->manifest['public_base_url']];
    }

    public function test_all_85_targets_apply_and_repeat_without_overwriting(): void
    {
        $args = $this->fixture();
        foreach (['ingredients', 'kitchen_equipment', 'recipes'] as $table) {
            DB::table($table)->insert(['id' => 999, $table === 'recipes' ? 'title' : 'name' => 'untouched', 'image_url' => 'https://assets.telmanfit.com.ua/keep.webp']);
        }
        $before = $this->snapshot();
        $dry = $args;
        unset($dry['--apply']);
        $dry['--dry-run'] = true;
        $dry['--manifest-file'] = base_path('docs/assets/r2-local-image-plan.json');
        $this->assertSame(0, Artisan::call('assets:match-r2-images', $dry), Artisan::output());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, Artisan::call('assets:match-r2-images', $args), Artisan::output());
        foreach ($this->manifest['updates'] as $row) {
            $this->assertSame($row['proposed_image_url'], DB::table($row['table'])->where('id', $row['id'])->value('image_url'));
        }
        $after = $this->snapshot();
        $this->assertSame(0, Artisan::call('assets:match-r2-images', $args), Artisan::output());
        $this->assertSame($after, $this->snapshot());
        $this->assertStringNotContainsString('APPLIED ', Artisan::output());
        foreach (['ingredients', 'kitchen_equipment', 'recipes'] as $table) {
            $this->assertSame('https://assets.telmanfit.com.ua/keep.webp', DB::table($table)->where('id', 999)->value('image_url'));
        }
    }

    public function test_catalog_writes_are_blocked_until_manifest_apply_finishes(): void
    {
        // Власна disposable schema, видима двом сесіям; міграції не використовуються.
        foreach (['ingredients', 'kitchen_equipment', 'recipes'] as $table) {
            DB::statement('DROP TABLE pg_temp.'.$table);
        }
        DB::statement('CREATE SCHEMA r2_manifest_lock_test');
        DB::statement('SET search_path TO r2_manifest_lock_test');
        foreach (['ingredients' => 'name', 'kitchen_equipment' => 'name', 'recipes' => 'title'] as $table => $name) {
            DB::statement('CREATE TABLE '.$table.' (id integer PRIMARY KEY, '.$name.' text, image_url text)');
        }
        $args = $this->fixture();
        DB::commit();
        $other = new \PDO('pgsql:host='.getenv('DB_HOST').';dbname=telmanfit_stage1_test', 'telmanfit_test_runner');
        $other->exec('SET search_path TO r2_manifest_lock_test');
        $other->exec("SET lock_timeout = '100ms'");
        $attempts = 0;
        $active = true;
        DB::listen(function ($query) use ($other, &$attempts, &$active): void {
            if (! $active || ! str_starts_with($query->sql, 'LOCK TABLE')) {
                return;
            }
            foreach (["UPDATE ingredients SET name = 'changed' WHERE id = 47", "INSERT INTO ingredients VALUES (999, 'extra', NULL)", 'DELETE FROM recipes WHERE id = 1'] as $sql) {
                try {
                    $other->exec($sql);
                    $this->fail('Concurrent catalog write escaped the transaction lock.');
                } catch (\PDOException $exception) {
                    $this->assertSame('55P03', $exception->getCode());
                    $attempts++;
                }
            }
        });
        try {
            $this->assertSame(0, Artisan::call('assets:match-r2-images', $args), Artisan::output());
            $this->assertSame(3, $attempts);
            $this->assertSame(1, $other->exec("UPDATE ingredients SET name = 'after commit' WHERE id = 47"));
        } finally {
            $active = false;
            $other = null;
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA r2_manifest_lock_test CASCADE');
        }
    }

    public static function mutations(): array
    {
        return array_map(fn ($case) => [$case], ['id', 'name', 'key', 'url', 'missing', 'extra', 'duplicate', 'duplicate-key', 'existing', 'host', 'exact', 'legacy', 'manifest-table', 'manifest-id', 'manifest-name', 'manifest-key', 'manifest-missing', 'manifest-extra']);
    }

    #[DataProvider('mutations')]
    public function test_mismatch_after_prior_dry_run_blocks_every_write(string $case): void
    {
        $args = $this->fixture();
        $dry = $args;
        unset($dry['--apply']);
        $dry['--dry-run'] = true;
        $dry['--manifest-file'] = base_path('docs/assets/r2-local-image-plan.json');
        $this->assertSame(0, Artisan::call('assets:match-r2-images', $dry), Artisan::output());
        $row = $this->manifest['updates'][0];
        $keys = array_column($this->manifest['updates'], 'r2_key');
        $file = null;
        if ($case === 'id') {
            DB::table($row['table'])->where('id', $row['id'])->update(['id' => 999]);
        } elseif ($case === 'name') {
            DB::table($row['table'])->where('id', $row['id'])->update(['name' => 'changed']);
        } elseif ($case === 'key') {
            $keys[0] = str_replace('.webp', '.jpg', $keys[0]);
        } elseif ($case === 'legacy') {
            $keys = array_map(fn ($key) => str_replace('kitchen_equipment/', 'equipment/', $key), $keys);
        } elseif ($case === 'missing') {
            array_shift($keys);
        } elseif ($case === 'extra') {
            DB::table('ingredients')->insert(['id' => 999, 'name' => 'extra']);
            $keys[] = 'ingredients/extra.webp';
        } elseif ($case === 'duplicate-key') {
            $keys[] = $keys[0];
        } elseif ($case === 'existing') {
            DB::table($row['table'])->where('id', $row['id'])->update(['image_url' => 'https://assets.telmanfit.com.ua/keep.webp']);
        } elseif ($case === 'host') {
            config(['presentation.image_hosts' => []]);
        } elseif ($case === 'exact') {
            unset($args['--exact-only']);
        } else {
            if ($case === 'url') {
                $this->manifest['updates'][0]['proposed_image_url'] .= '?changed';
            } elseif (str_starts_with($case, 'manifest-')) {
                $field = substr($case, 9);
                if ($field === 'missing') {
                    array_shift($this->manifest['updates']);
                    $this->manifest['expected_updates']--;
                } elseif ($field === 'extra') {
                    $extra = $row;
                    $extra['id'] = 999;
                    $extra['r2_key'] = 'ingredients/extra.webp';
                    $extra['proposed_image_url'] = $this->manifest['public_base_url'].'/ingredients/extra.webp';
                    $this->manifest['updates'][] = $extra;
                    $this->manifest['expected_updates']++;
                } else {
                    $key = $field === 'key' ? 'r2_key' : $field;
                    $this->manifest['updates'][0][$key] = $field === 'id' ? 999 : 'changed';
                }
            } else {
                $this->manifest['updates'][] = $row;
                $this->manifest['expected_updates']++;
            }
            $file = tempnam(sys_get_temp_dir(), 'r2-manifest-');
            file_put_contents($file, json_encode($this->manifest, JSON_THROW_ON_ERROR));
            $args['--manifest-file'] = $file;
        }
        $this->source($keys);
        $before = $this->snapshot();
        try {
            $this->assertSame(1, Artisan::call('assets:match-r2-images', $args), Artisan::output());
            $this->assertSame($before, $this->snapshot());
        } finally {
            if ($file !== null) {
                unlink($file);
            }
        }
    }
}
