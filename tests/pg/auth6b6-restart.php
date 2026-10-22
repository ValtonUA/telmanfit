<?php

use App\Services\Auth\Budgets\BudgetKey;
use App\Services\Auth\Budgets\BudgetScope;
use App\Services\Auth\Budgets\PostgresBudgets;
use Illuminate\Support\Facades\DB;

require __DIR__.'/auth2-bootstrap.php';
config(['auth4.rate_key' => 'synthetic-restart-stable-rate-key-32minimum']);
$key = new BudgetKey(BudgetScope::OtpCooldown, 'restart-sentinel');
$start = DB::selectOne('SELECT pg_postmaster_start_time() t')->t;
if (($argv[1] ?? '') === 'before') {
    if (! app(PostgresBudgets::class)->admit([$key])->allowed) {
        throw new RuntimeException('restart_fixture_unavailable');
    }
    echo json_encode(['postmaster_started' => $start]);
} else {
    $before = json_decode(file_get_contents($argv[2]), true);
    if ($start === $before['postmaster_started'] || app(PostgresBudgets::class)->admit([$key])->allowed) {
        throw new RuntimeException('restart_refunded_budget');
    }
    file_put_contents(base_path('docs/evidence/auth6b-b6/restart.json'), json_encode(['synthetic_only' => true,
        'postmaster_restarted' => true, 'committed_budget_retained' => true, 'next_request_denied' => true], JSON_PRETTY_PRINT)."\n");
    chmod(base_path('docs/evidence/auth6b-b6/restart.json'), 0644);
    echo "B6 real PostgreSQL postmaster restart PASS: committed window survives, next request denied\n";
}
