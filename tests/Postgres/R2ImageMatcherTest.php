<?php

namespace Tests\Postgres;

use App\Services\Catalog\R2ObjectSource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class R2ImageMatcherTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_25_020000_add_plan_presentation.php'))->up();
        (require database_path('migrations/2026_10_26_000100_add_kitchen_equipment_image_url.php'))->up();
        config(['presentation.image_hosts' => ['images.example.com']]);
    }

    public function test_real_binding_reads_mock_s3_and_preserves_all_three_catalogs(): void
    {
        $this->ingredient('rice');
        DB::table('kitchen_equipment')->insert(['name' => 'oven']);
        DB::table('recipes')->insert(['title' => 'rice bowl', 'created_at' => now()]);
        config(['r2' => [
            'endpoint' => 'https://synthetic.r2.cloudflarestorage.com',
            'bucket' => 'synthetic-bucket',
            'access_key_id' => 'synthetic-access',
            'secret_access_key' => 'synthetic-secret',
        ]]);
        Http::preventStrayRequests();
        Http::fakeSequence()->push('<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            .'<Contents><Key>ingredients/rice.webp</Key></Contents>'
            .'<Contents><Key>kitchen_equipment/oven.webp</Key></Contents>'
            .'<Contents><Key>recipes/rice_bowl.webp</Key></Contents>'
            .'<IsTruncated>false</IsTruncated></ListBucketResult>');
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['ingredients', 'recipes', 'kitchen_equipment']);
        $before = $snapshot();
        $this->assertSame(0, Artisan::call('assets:match-r2-images', ['--dry-run' => true]));
        $this->assertStringContainsString('confident: 3; ambiguous: 0; unmatched: 0; unsupported: 0', Artisan::output());
        $this->assertSame($before, $snapshot());
        Http::assertSentCount(1);
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
            ['key' => 'ingredients/chicken_file.webp'],
            ['key' => 'ingredients/zzzzzzzzzzzzzzzzzzzzzzzz.webp'],
            ['key' => 'misc/readme.txt'],
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
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('confident: 3; ambiguous: 4; unmatched: 1; unsupported: 1', $output);
        $this->assertStringContainsString('#'.$recipeId.' buckwheat mushrooms', $output);
        $this->assertSame('https://images.example.com/old.png', DB::table('ingredients')->where('id', $existingId)->value('image_url'));
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
