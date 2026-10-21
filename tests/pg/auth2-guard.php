<?php

use Illuminate\Support\Facades\DB;

// Виконується до будь-якого SQL/migrate, навіть якщо випадково підхоплено .env.
if (getenv('TELMANFIT_PG_TEST') !== '1'
    || ! preg_match('~^/tmp/telmanfit-stage1\.[a-zA-Z0-9]+/socket$~', (string) getenv('DB_HOST'))
    || getenv('DB_DATABASE') !== 'telmanfit_stage1_test'
    || getenv('DB_USERNAME') !== 'telmanfit_test_runner'
    || getenv('DB_PORT') !== '5432'
    || DB::getDriverName() !== 'pgsql'
    || config('database.connections.pgsql.host') !== getenv('DB_HOST')
    || config('database.connections.pgsql.database') !== getenv('DB_DATABASE')) {
    throw new RuntimeException('AUTH-2 потребує новий ізольований PostgreSQL 18 із runner.');
}
$identity = DB::selectOne("SELECT current_database() AS db, current_user AS usr, current_setting('server_version_num')::int / 10000 AS major, (SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname=current_user) AS privileged");
if ($identity->db !== 'telmanfit_stage1_test' || $identity->usr !== 'telmanfit_test_runner' || $identity->major !== 18 || $identity->privileged) {
    throw new RuntimeException('Небезпечне з’єднання AUTH-2.');
}
