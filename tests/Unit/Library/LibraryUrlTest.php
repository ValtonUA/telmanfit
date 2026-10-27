<?php

namespace Tests\Unit\Library;

use App\Support\Library\ExternalContentUrl;
use PHPUnit\Framework\TestCase;

class LibraryUrlTest extends TestCase
{
    public function test_public_https_only(): void
    {
        $this->assertSame('https://www.youtube.com/watch?v=abc', ExternalContentUrl::safe('https://www.youtube.com/watch?v=abc'));
        foreach (['http://example.org', 'https://127.0.0.1/', 'https://localhost/', 'https://192.168.1.1/',
            'https://user:secret@example.org', 'https://example.invalid', 'https://example.org:8443/',
            'https://example.org/#fragment', 'javascript:alert(1)', '//example.org', 'https://example.org/'."\n"] as $url) {
            $this->assertNull(ExternalContentUrl::safe($url), $url);
        }
    }
}
