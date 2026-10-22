<?php

namespace Tests\Unit;

use App\Support\PhpRuntimePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhpRuntimePolicyTest extends TestCase
{
    #[DataProvider('versions')]
    public function test_runtime_security_boundaries(string $version, bool $supported): void
    {
        $this->assertSame($supported, PhpRuntimePolicy::supports($version));
    }

    public static function versions(): array
    {
        return [
            'unsupported 8.2 even when patched' => ['8.2.35', false],
            'vulnerable 8.3' => ['8.3.35', false],
            'patched 8.3' => ['8.3.36', true],
            'later 8.3 patch' => ['8.3.37', true],
            'vulnerable Cloud runtime' => ['8.4.26', false],
            'patched 8.4' => ['8.4.27', true],
            'later 8.4 patch' => ['8.4.28', true],
            'vulnerable 8.5' => ['8.5.11', false],
            'patched local runtime' => ['8.5.12', true],
            'later 8.5 patch' => ['8.5.13', true],
            'release candidate' => ['8.5.12RC1', false],
            'development build' => ['8.4.27-dev', false],
            'unverified vendor suffix' => ['8.4.27-custom', false],
            'unknown future branch' => ['8.6.0', false],
            'unknown major' => ['9.0.0', false],
            'malformed version' => ['8.5', false],
        ];
    }
}
