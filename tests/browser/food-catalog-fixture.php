<?php

use Illuminate\Support\Facades\DB;

require __DIR__.'/../pg/auth2-bootstrap.php';
if (($argv[1] ?? '') === 'revoke') {
    DB::table('users')->where('email', 'recipe-browser@example.invalid')->increment('auth_version');
    exit;
}
if (($argv[1] ?? '') === 'hash') {
    $state = [];
    foreach (['ingredients', 'ingredient_uoms', 'uoms', 'ingredient_categories', 'recipe_categories', 'kitchen_equipment', 'kitchen_equipment_categories', 'foods'] as $table) {
        $state[$table] = DB::table($table)->orderBy($table === 'ingredient_uoms' ? 'ingredient_id' : 'id')->get()->all();
    }
    echo hash('sha256', json_encode($state));
    exit;
}
throw new RuntimeException('Невідома synthetic дія');
