<?php

namespace Tests\Feature;

use App\Services\Catalog\R2ObjectSource;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class R2ImageMatcherCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('ingredients', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->text('image_url')->nullable();
        });
        Schema::create('kitchen_equipment', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->text('image_url')->nullable();
        });
        Schema::create('recipes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->text('image_url')->nullable();
        });
        config(['presentation.image_hosts' => ['images.example.com']]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('kitchen_equipment');
        Schema::dropIfExists('recipes');
        parent::tearDown();
    }

    public function test_dry_run_is_read_only_and_apply_skips_ambiguity_and_existing_images(): void
    {
        $ingredient = DB::table('ingredients')->insertGetId(['name' => 'chicken fillet']);
        $ambiguousA = DB::table('ingredients')->insertGetId(['name' => 'green pepper']);
        $ambiguousB = DB::table('ingredients')->insertGetId(['name' => 'green pepper']);
        $existing = DB::table('ingredients')->insertGetId(['name' => 'sunflower oil', 'image_url' => 'https://images.example.com/current.webp']);
        $equipment = DB::table('kitchen_equipment')->insertGetId(['name' => 'kitchen scale']);
        $recipe = DB::table('recipes')->insertGetId(['title' => 'buckwheat mushrooms']);

        app()->instance(R2ObjectSource::class, new class implements R2ObjectSource
        {
            public function list(): array
            {
                return [
                    ['key' => 'ingredients/chicken_fillet.webp'],
                    ['key' => 'ingredients/green_pepper.webp'],
                    ['key' => 'ingredients/sunflower_oil.webp'],
                    ['key' => 'kitchen_equipment/kitchen_scale.webp'],
                    ['key' => 'recipes/buckwheat_mushrooms.webp'],
                ];
            }
        });

        $this->assertSame(0, Artisan::call('assets:match-r2-images', ['--dry-run' => true]));
        $this->assertNull(DB::table('ingredients')->where('id', $ingredient)->value('image_url'));
        $this->assertNull(DB::table('kitchen_equipment')->where('id', $equipment)->value('image_url'));
        $this->assertNull(DB::table('recipes')->where('id', $recipe)->value('image_url'));

        $this->assertSame(0, Artisan::call('assets:match-r2-images', [
            '--apply' => true,
            '--public-base-url' => 'https://images.example.com',
        ]));
        $this->assertSame('https://images.example.com/ingredients/chicken_fillet.webp', DB::table('ingredients')->where('id', $ingredient)->value('image_url'));
        $this->assertSame('https://images.example.com/kitchen_equipment/kitchen_scale.webp', DB::table('kitchen_equipment')->where('id', $equipment)->value('image_url'));
        $this->assertSame('https://images.example.com/recipes/buckwheat_mushrooms.webp', DB::table('recipes')->where('id', $recipe)->value('image_url'));
        $this->assertNull(DB::table('ingredients')->whereIn('id', [$ambiguousA, $ambiguousB])->whereNotNull('image_url')->value('image_url'));
        $this->assertSame('https://images.example.com/current.webp', DB::table('ingredients')->where('id', $existing)->value('image_url'));
    }
}
