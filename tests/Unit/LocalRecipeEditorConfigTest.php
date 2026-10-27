<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalRecipeEditorConfigTest extends TestCase
{
    public static function profiles(): array
    {
        return [['production', 'true', 'true', false], ['local', 'false', 'true', false], ['local', 'true', 'false', false], ['local', 'true', 'true', true]];
    }

    #[DataProvider('profiles')]
    public function test_only_explicit_local_compose_can_enable_editor(string $environment, string $compose, string $enabled, bool $expected): void
    {
        $old = [];
        foreach (['APP_ENV' => $environment, 'TELMANFIT_LOCAL_COMPOSE' => $compose, 'TELMANFIT_LOCAL_RECIPE_EDITOR' => $enabled] as $key => $value) {
            $old[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv($key.'='.$value);
        }
        try {
            $actual = require config_path('recipes.php');
            $this->assertSame($expected, $actual['batch_editor_enabled']);
        } finally {
            foreach ($old as $key => [$server, $env, $process]) {
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
                putenv($process === false ? $key : $key.'='.$process);
            }
        }
    }
}
