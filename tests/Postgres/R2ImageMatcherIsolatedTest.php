<?php

namespace Tests\Postgres;

use App\Services\Catalog\R2ObjectSource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

    public function test_canonical_apply_is_idempotent_and_preserves_existing_urls_in_all_catalogs(): void
    {
        foreach (['ingredients' => 'name', 'kitchen_equipment' => 'name', 'recipes' => 'title'] as $table => $name) {
            DB::table($table)->insert([
                ['id' => 1, $name => 'rice', 'image_url' => null],
                ['id' => 2, $name => 'existing', 'image_url' => 'https://images.example.com/keep.webp'],
            ]);
        }
        $this->source(['ingredients/rice.webp', 'kitchen_equipment/rice.webp', 'equipment/rice.webp', 'recipes/rice.webp', 'ingredients/existing.webp', 'kitchen_equipment/existing.webp', 'recipes/existing.webp']);
        $before = $this->snapshot();
        $this->assertSame(0, Artisan::call('assets:match-r2-images', ['--dry-run' => true, '--exact-only' => true]));
        $this->assertSame($before, $this->snapshot());
        $args = ['--apply' => true, '--exact-only' => true, '--public-base-url' => 'https://images.example.com'];
        $this->assertSame(0, Artisan::call('assets:match-r2-images', $args));
        $this->assertStringContainsString('Ignored legacy objects: 1', Artisan::output());
        foreach (['ingredients', 'kitchen_equipment', 'recipes'] as $table) {
            $this->assertSame('https://images.example.com/'.$table.'/rice.webp', DB::table($table)->where('id', 1)->value('image_url'));
            $this->assertSame('https://images.example.com/keep.webp', DB::table($table)->where('id', 2)->value('image_url'));
        }
        $after = $this->snapshot();
        $this->assertSame(0, Artisan::call('assets:match-r2-images', $args));
        $this->assertSame($after, $this->snapshot());
        $this->assertStringNotContainsString('APPLIED ', Artisan::output());
    }

    public function test_disallowed_host_and_duplicate_canonical_objects_cannot_write(): void
    {
        DB::table('kitchen_equipment')->insert(['id' => 1, 'name' => 'oven']);
        $this->source(['kitchen_equipment/oven.webp']);
        $before = $this->snapshot();
        $this->assertSame(1, Artisan::call('assets:match-r2-images', ['--apply' => true, '--public-base-url' => 'https://not-allowed.example.com']));
        $this->assertSame($before, $this->snapshot());
        $this->source(['kitchen_equipment/oven.webp', 'kitchen_equipment/oven.jpg']);
        $this->assertSame(0, Artisan::call('assets:match-r2-images', ['--apply' => true, '--public-base-url' => 'https://images.example.com']));
        $this->assertSame($before, $this->snapshot());
        $this->assertStringContainsString('ambiguous: 2', Artisan::output());
    }
}
