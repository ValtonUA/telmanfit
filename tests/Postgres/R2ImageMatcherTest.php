<?php

namespace Tests\Postgres;

use App\Services\Catalog\R2ObjectSource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class R2ImageMatcherTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_25_020000_add_plan_presentation.php'))->up();
        (require database_path('migrations/2026_10_26_000100_add_kitchen_equipment_image_url.php'))->up();
        config(['presentation.image_hosts' => ['images.example.com']]);
    }

    public function test_dry_run_is_read_only_and_apply_only_writes_unique_empty_matches(): void
    {
        $ingredientId = $this->ingredient('chicken fillet');
        $ambiguousOne = $this->ingredient('green pepper');
        $ambiguousTwo = $this->ingredient('green pepper');
        $existingId = $this->ingredient('sunflower oil', 'https://images.example.com/old.png');
        $equipmentId = DB::table('kitchen_equipment')->insertGetId(['name' => 'kitchen scale']);
        $recipeId = DB::table('recipes')->insertGetId(['title' => 'buckwheat mushrooms', 'created_at' => now()]);
        $objects = [
            ['key' => 'ingredients/chicken_fillet.webp'],
            ['key' => 'ingredients/green_pepper.webp'],
            ['key' => 'ingredients/sunflower_oil.webp'],
            ['key' => 'kitchen_equipment/kitchen_scale.webp'],
            ['key' => 'equipment/kitchen_scale.webp'],
            ['key' => 'recipes/buckwheat_mushrooms.webp'],
        ];
        app()->instance(R2ObjectSource::class, new class($objects) implements R2ObjectSource
        {
            public function __construct(private array $objects) {}

            public function list(): array
            {
                return $this->objects;
            }
        });

        $exit = Artisan::call('assets:match-r2-images', ['--dry-run' => true]);
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertNull(DB::table('ingredients')->where('id', $ingredientId)->value('image_url'));
        $this->assertNull(DB::table('kitchen_equipment')->where('id', $equipmentId)->value('image_url'));
        $this->assertNull(DB::table('recipes')->where('id', $recipeId)->value('image_url'));
        $exit = Artisan::call('assets:match-r2-images', [
            '--apply' => true,
            '--public-base-url' => 'https://images.example.com',
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('https://images.example.com/ingredients/chicken_fillet.webp', DB::table('ingredients')->where('id', $ingredientId)->value('image_url'));
        $this->assertNull(DB::table('ingredients')->whereIn('id', [$ambiguousOne, $ambiguousTwo])->whereNotNull('image_url')->value('image_url'));
        $this->assertNull(DB::table('kitchen_equipment')->where('id', $equipmentId)->value('image_url'));
        $this->assertSame('https://images.example.com/recipes/buckwheat_mushrooms.webp', DB::table('recipes')->where('id', $recipeId)->value('image_url'));
        $this->assertSame('https://images.example.com/old.png', DB::table('ingredients')->where('id', $existingId)->value('image_url'));
    }

    private function ingredient(string $name, ?string $imageUrl = null): int
    {
        $foodId = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);

        return DB::table('ingredients')->insertGetId([
            'food_id' => $foodId,
            'name' => $name,
            'kcal_per_100g' => 100,
            'protein_per_100g' => 10,
            'fat_per_100g' => 1,
            'carbs_per_100g' => 2,
            'image_url' => $imageUrl,
        ]);
    }
}
