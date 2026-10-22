<?php

use App\Services\Auth\Restore\SyntheticEpoch;
use Illuminate\Support\Facades\DB;

require __DIR__.'/auth2-bootstrap.php';
DB::table('auth_security_runtime')->insertOrIgnore(['id' => 1, 'restore_epoch' => SyntheticEpoch::INITIAL,
    'last_reconciled_at' => DB::raw('clock_timestamp()'), 'issuance_not_before' => DB::raw('clock_timestamp()'), 'state' => 'ready']);
