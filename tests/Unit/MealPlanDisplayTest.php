<?php

namespace Tests\Unit;

use App\Support\Presentation\MealPlanDisplay;
use App\Support\Presentation\PublicImageUrl;
use DomainException;
use Tests\TestCase;

class MealPlanDisplayTest extends TestCase
{
    public function test_exact_rounding_weekday_and_fail_closed_values(): void
    {
        $this->assertSame('День 1 (Вт)', MealPlanDisplay::day(1, 2));
        $this->assertSame('День 8 (Вт)', MealPlanDisplay::day(8, 2));
        $this->assertSame('День 7 (Нд)', MealPlanDisplay::day(7, 1));
        $this->assertSame('10000000000000001', MealPlanDisplay::decimal('10000000000000000.5', 0));
        $this->assertSame('1.3', MealPlanDisplay::decimal('1.25'));
        $this->assertSame('1 ккал · Б 1.3 · Ж 0.0 · В 2.0', MealPlanDisplay::nutrition(['kcal' => '0.500000', 'protein' => '1.250000', 'fat' => '0', 'carbs' => '2.000000']));
        foreach (['-0.1', '1e3', 'NaN', ' 1', '1,2', 1.25, null] as $value) {
            $this->assertSame('КБЖВ недоступні', MealPlanDisplay::nutrition(['kcal' => $value]));
            try {
                MealPlanDisplay::decimal($value);
                $this->fail('Нестандартне число має бути відхилене');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_images_require_explicit_exact_host_and_public_https(): void
    {
        $url = 'https://images.example.com/cover.png?v=2';
        config(['presentation.image_hosts' => []]);
        $this->assertNull(PublicImageUrl::safe($url));
        config(['presentation.image_hosts' => ['images.example.com', '127.0.0.1', 'localhost', 'images.internal', '192.168.1.1', '127.1', 'images.lan']]);
        $this->assertSame($url, PublicImageUrl::safe($url));
        foreach ([null, '', 'http://images.example.com/a.png', 'javascript:alert(1)', 'data:image/png;base64,abc', '//images.example.com/a.png',
            'https://images.example.com.evil.com/a.png', 'https://evil.images.example.com/a.png', 'https://user@images.example.com/a.png',
            'https://images.example.com:443/a.png', 'https://images.example.com/a.svg', 'https://images.example.com/a.%73vg',
            'https://images.example.com/a.html', 'https://images.example.com/a.png#x', 'https://127.0.0.1/a.png',
            'https://192.168.1.1/a.png', 'https://localhost/a.png', 'https://127.1/a.png', 'https://images.lan/a.png', 'https://images.internal/a.png', 'https://images.example.com/" onerror="x'] as $bad) {
            $this->assertNull(PublicImageUrl::safe($bad), (string) $bad);
        }
    }
}
