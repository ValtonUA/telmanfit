<?php

namespace App\Support;

final class PhpRuntimePolicy
{
    public static function supports(string $version): bool
    {
        // Лише стабільні перевірені гілки; vendor-суфікс не доводить backport.
        if (! preg_match('/\A(8\.[345])\.([0-9]+)\z/', $version, $matches)) {
            return false;
        }

        $minimum = ['8.3' => 36, '8.4' => 27, '8.5' => 12];

        return (int) $matches[2] >= $minimum[$matches[1]];
    }
}
