<?php

use App\Services\Auth\Restore\LocalComposeEpoch;
use App\Services\Auth\Restore\RestoreFence;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require __DIR__.'/load-env.php';
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $connection = DB::connection();
    $identity = $connection->selectOne("SELECT current_database() AS database, current_user AS username, current_setting('server_version_num')::integer AS version_num, shobj_description(oid, 'pg_database') AS proof, (SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname=current_user) AS privileged FROM pg_database WHERE datname=current_database()");
    $expectedDatabase = (string) getenv('DB_DATABASE');
    $clusterId = (string) getenv('LOCAL_CLUSTER_ID');
    if ($identity->database !== $expectedDatabase
        || ! preg_match('/^telmanfit_local_[a-f0-9]{16}$/D', $expectedDatabase)
        || $identity->username !== 'telmanfit_local_runner'
        || intdiv((int) $identity->version_num, 10000) !== 18
        || (bool) $identity->privileged
        || ! preg_match('/^[a-f0-9]{16}$/D', $clusterId)
        || $identity->proof !== 'telmanfit-compose:'.$clusterId
        || app()->environment() !== 'local'
        || config('local_compose.enabled') !== true) {
        throw new RuntimeException('локальна identity PostgreSQL не збігається з очікуваною');
    }

    app(LocalComposeEpoch::class)->current();
    app(RestoreFence::class)->assert();

    $connection->beginTransaction();
    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

    $tables = $connection->select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename");
    if (! in_array('migrations', array_map(fn ($table) => $table->tablename, $tables), true)) {
        throw new RuntimeException('migration history table відсутня');
    }

    $fingerprints = [];
    foreach ($tables as $table) {
        $name = str_replace('"', '""', $table->tablename);
        $result = $connection->selectOne('SELECT count(*)::bigint AS rows, md5(coalesce(string_agg(md5(row_to_json(t)::text), \'\' ORDER BY row_to_json(t)::text), \'\')) AS fingerprint FROM public."'.$name.'" AS t');
        $fingerprints[$table->tablename] = [
            'rows' => (int) $result->rows,
            'fingerprint' => $result->fingerprint,
        ];
    }

    $catalog = $connection->selectOne("SELECT count(*)::bigint AS rows, count(*) FILTER (WHERE image_url IS NOT NULL)::bigint AS urls, md5(coalesce(string_agg(table_name||':'||id::text||':'||coalesce(image_url, '<NULL>'), E'\\n' ORDER BY table_name, id), '')) AS fingerprint FROM (SELECT 'ingredients'::text AS table_name,id,image_url FROM ingredients UNION ALL SELECT 'kitchen_equipment',id,image_url FROM kitchen_equipment UNION ALL SELECT 'recipes',id,image_url FROM recipes) AS catalog_rows");

    $migrator = app('migrator');
    $migrationFiles = $migrator->getMigrationFiles(database_path('migrations'));
    $ran = $migrator->getRepository()->getRan();
    $pending = array_values(array_diff(array_keys($migrationFiles), $ran));

    $schema = [
        'tables' => array_map(fn ($row) => $row->tablename, $connection->select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")),
        'functions' => array_map(fn ($row) => $row->proname, $connection->select("SELECT DISTINCT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' ORDER BY p.proname")),
        'triggers' => array_map(fn ($row) => $row->tgname, $connection->select("SELECT t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE c.relnamespace='public'::regnamespace AND NOT t.tgisinternal ORDER BY t.tgname")),
        'views' => array_map(fn ($row) => $row->viewname, $connection->select("SELECT viewname FROM pg_views WHERE schemaname='public' ORDER BY viewname")),
    ];

    $connection->rollBack();

    echo json_encode([
        'status' => 'PASS',
        'identity' => [
            'database' => $identity->database,
            'username' => $identity->username,
            'postgres_major' => intdiv((int) $identity->version_num, 10000),
            'cluster_proof' => $identity->proof,
            'privileged' => (bool) $identity->privileged,
        ],
        'restore_fence' => 'PASS',
        'migrations' => [
            'ran' => $ran,
            'pending' => $pending,
        ],
        'tables' => $fingerprints,
        'catalog' => [
            'rows' => (int) $catalog->rows,
            'urls' => (int) $catalog->urls,
            'fingerprint' => $catalog->fingerprint,
        ],
        'schema' => $schema,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $error) {
    if (isset($connection) && $connection->transactionLevel() > 0) {
        $connection->rollBack();
    }
    fwrite(STDERR, 'LOCAL_UPGRADE_CHECK_FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
