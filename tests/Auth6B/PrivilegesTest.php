<?php

namespace Tests\Auth6B;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrivilegesTest extends TestCase
{
    public function test_runtime_cannot_mutate_registry_approvals_gates_or_execute_incomplete_recovery(): void
    {
        require __DIR__.'/../pg/auth2-guard.php';
        DB::statement('GRANT SELECT ON auth_recovery_gates TO telmanfit_recovery_runtime');
        foreach ([
            'SELECT auth6b_quarantine(gen_random_uuid(),gen_random_uuid(),repeat(\'a\',64))',
            'SELECT auth6b_exchange(gen_random_uuid(),repeat(\'a\',64),repeat(\'b\',64),gen_random_uuid())',
            'SELECT auth6b_complete(gen_random_uuid())',
            'SELECT auth6b_close(gen_random_uuid(),\'cancelled\')',
            'INSERT INTO auth_recovery_approvals(case_id) VALUES(gen_random_uuid())',
            'INSERT INTO auth_recovery_principals(id) VALUES(gen_random_uuid())',
            'UPDATE auth_recovery_gates SET released_at=clock_timestamp()',
            'TRUNCATE auth_recovery_events',
            'ALTER TABLE auth_recovery_gates DISABLE TRIGGER ALL',
            'SET ROLE telmanfit_test_runner',
        ] as $sql) {
            DB::beginTransaction();
            try {
                // SET ROLE назад до session_user дозволено PG; перевіряємо заборону privileged role через інший тест.
                if ($sql === 'SET ROLE telmanfit_test_runner') {
                    $sql = 'SET ROLE postgres';
                }
                DB::statement('SET LOCAL ROLE telmanfit_recovery_runtime');
                $this->assertSame(0, DB::table('auth_recovery_gates')->whereRaw('false')->count());
                try {
                    DB::statement($sql);
                    $this->fail('Runtime privilege bypass');
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->errorInfo[0]);
                }
            } finally {
                DB::rollBack();
            }
        }
    }
}
