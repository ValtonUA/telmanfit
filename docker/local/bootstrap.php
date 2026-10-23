<?php

use App\Services\Auth\Restore\LocalComposeEpoch;
use App\Services\Auth\Restore\RestoreFence;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

try {
    require __DIR__.'/load-env.php';
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $epoch = app(LocalComposeEpoch::class)->current();
    $state = '/tmp/telmanfit-auth4-compose';
    $lock = fopen("$state/bootstrap.lock", 'c');
    flock($lock, LOCK_EX);
    $identity = DB::selectOne("SELECT current_database() db, current_user usr, current_setting('server_version_num')::int / 10000 major, shobj_description(oid, 'pg_database') proof, (SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname=current_user) privileged FROM pg_database WHERE datname=current_database()");
    if ($identity->db !== getenv('DB_DATABASE') || $identity->usr !== 'telmanfit_local_runner' || $identity->major !== 18
        || $identity->privileged || $identity->proof !== 'telmanfit-compose:'.getenv('LOCAL_CLUSTER_ID')) {
        throw new RuntimeException('БД не належить цьому новому ізольованому Compose cluster.');
    }
    if (! is_file("$state/database-ready")) {
        $tables = DB::selectOne("SELECT count(*) n FROM information_schema.tables WHERE table_schema='public'")->n;
        if ($tables !== 0) {
            throw new RuntimeException('Непорожня БД: автоматичні міграції заборонені. Перевірте попередню помилку запуску.');
        }
        // Лише порожня БД з незалежним proof, створена entrypoint цього stack.
        if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
            throw new RuntimeException('Початкові міграції не завершені. '.Artisan::output());
        }
        $access = json_decode(file_get_contents("$state/access.json"), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($epoch, $access): void {
            DB::table('auth_security_runtime')->insert(['id' => 1, 'restore_epoch' => $epoch, 'state' => 'ready',
                'last_reconciled_at' => DB::raw('clock_timestamp()'), 'issuance_not_before' => DB::raw('clock_timestamp()')]);
            $id = DB::table('users')->insertGetId(['first_name' => 'Локальний', 'last_name' => 'адміністратор', 'role' => 'admin',
                'email' => $access['email'], 'email_normalized' => $access['email'], 'email_verified_at' => now(),
                'password' => Hash::make($access['password'])]);
            $token = parse_url($access['invitation'], PHP_URL_FRAGMENT);
            DB::table('client_invitations')->insert(['token_hash' => hash('sha256', substr($token, 7)),
                'created_by_user_id' => $id, 'coach_user_id' => $id]);
        });
        file_put_contents("$state/database-ready", '1');
    }
    // Рестарт ніколи не запускає міграції, не перевидає epoch/MFA/облікові записи.
    app(RestoreFence::class)->assert();
    $migrator = app('migrator');
    if (array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan())) {
        throw new RuntimeException('Нові міграції потребують окремого погодженого upgrade локальної БД.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Локальний bootstrap відхилено: '.(get_class($error) === RuntimeException::class ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
}
