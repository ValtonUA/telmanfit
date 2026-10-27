<?php

namespace Tests\Unit;

use App\Services\Catalog\R2ImageMatcher;
use PHPUnit\Framework\TestCase;

class R2CanonicalImageMatcherTest extends TestCase
{
    public function test_canonical_prefix_wins_independently_of_order_and_legacy_never_falls_back(): void
    {
        $records = ['kitchen_equipment' => [['id' => 7, 'name' => 'Духовка', 'image_url' => null]]];
        $aliases = ['kitchen_equipment' => ['7' => ['oven']]];
        foreach ([['equipment/oven.webp', 'kitchen_equipment/oven.webp'], ['kitchen_equipment/oven.webp', 'equipment/oven.webp']] as $keys) {
            $plan = (new R2ImageMatcher)->plan(array_map(fn ($key) => ['key' => $key], $keys), $records, $aliases, true);
            $byKey = array_column($plan, null, 'key');
            $this->assertSame('confident', $byKey['kitchen_equipment/oven.webp']['status']);
            $this->assertSame(7, $byKey['kitchen_equipment/oven.webp']['record']['id']);
            $this->assertSame('ignored', $byKey['equipment/oven.webp']['status']);
        }
        $this->assertSame('ignored', (new R2ImageMatcher)->plan([['key' => 'equipment/oven.webp']], $records, $aliases)[0]['status']);
    }

    public function test_exact_only_keeps_real_ambiguity_but_drops_fuzzy_candidates(): void
    {
        $records = ['ingredients' => [
            ['id' => 1, 'name' => 'rice', 'image_url' => null],
            ['id' => 2, 'name' => 'rice', 'image_url' => null],
        ]];
        $objects = [['key' => 'ingredients/rice.webp'], ['key' => 'ingredients/ricee.webp']];
        $matcher = new R2ImageMatcher;
        $this->assertSame('ambiguous', $matcher->plan($objects, $records)[1]['status']);
        $plan = $matcher->plan($objects, $records, [], true);
        $this->assertSame('ambiguous', $plan[0]['status']);
        $this->assertCount(2, $plan[0]['suggestions']);
        $this->assertSame('unmatched', $plan[1]['status']);
        $this->assertSame([], $plan[1]['suggestions']);
    }

    public function test_two_canonical_objects_for_one_record_still_conflict(): void
    {
        $plan = (new R2ImageMatcher)->plan(
            [['key' => 'kitchen_equipment/oven.webp'], ['key' => 'kitchen_equipment/oven.jpg']],
            ['kitchen_equipment' => [['id' => 7, 'name' => 'oven', 'image_url' => null]]], [], true
        );
        $this->assertSame(['ambiguous', 'ambiguous'], array_column($plan, 'status'));
    }
}
