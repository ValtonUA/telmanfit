<?php

use Illuminate\Support\Facades\DB;

require __DIR__.'/../pg/auth2-bootstrap.php';
if (($argv[1] ?? '') === 'fault') {
    DB::unprepared("CREATE FUNCTION b6_http_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'b6-redaction-canary'; END $$; CREATE TRIGGER b6_http_fault BEFORE INSERT ON auth_rate_budgets FOR EACH ROW EXECUTE FUNCTION b6_http_fault()");
} elseif (($argv[1] ?? '') === 'recover') {
    DB::unprepared('DROP TRIGGER b6_http_fault ON auth_rate_budgets; DROP FUNCTION b6_http_fault()');
} else {
    $coach = DB::table('users')->insertGetId(['name' => 'B6 HTTP synthetic coach', 'role' => 'admin']);
    $user = DB::table('users')->insertGetId(['name' => 'B6 HTTP synthetic client', 'role' => 'client', 'email' => 'b6-http-known@example.invalid',
        'email_normalized' => 'b6-http-known@example.invalid', 'email_verified_at' => DB::raw('clock_timestamp()')]);
    DB::table('clients')->insert(['user_id' => $user, 'coach_user_id' => $coach]);
}
