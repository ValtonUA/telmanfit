<?php

use App\Services\Catalog\R2ImageMatcher;
use App\Services\Catalog\R2ObjectSource;
use App\Support\Presentation\PublicImageUrl;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Виконує лише погоджений dry-run; CLI apply тут недоступний.
try {
    require 'docker/local/load-env.php';
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if (! $app->environment('local') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.host') !== 'database' || ! str_starts_with((string) config('database.connections.pgsql.database'), 'telmanfit_local_')) {
        throw new RuntimeException('Unexpected environment');
    }
    $hosts = config('presentation.image_hosts');
    if (count($hosts) !== 1 || $hosts[0] !== 'assets.telmanfit.com.ua') {
        throw new RuntimeException('Unexpected public host');
    }
    $base = 'https://'.$hosts[0];
    $aliases = json_decode(file_get_contents('/audit/aliases.json'), true, 512, JSON_THROW_ON_ERROR);
    $objects = $app->make(R2ObjectSource::class)->list();
    $app->instance(R2ObjectSource::class, new class($objects) implements R2ObjectSource
    {
        public function __construct(private array $objects) {}

        public function list(): array
        {
            return $this->objects;
        }
    });
    $db = DB::connection();
    $db->statement('SET default_transaction_read_only = on');
    $db->beginTransaction();
    $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $records = [];
    foreach (['ingredients' => 'name', 'kitchen_equipment' => 'name', 'recipes' => 'title'] as $table => $name) {
        $records[$table] = $db->table($table)->orderBy('id')->get(['id', $name.' as name', 'image_url'])->map(fn ($r) => (array) $r)->all();
    }
    $before = hash('sha256', json_encode($records));
    $output = new BufferedOutput;
    $exit = $kernel->handle(new ArrayInput(['command' => 'assets:match-r2-images', '--dry-run' => true, '--exact-only' => true, '--aliases-file' => '/audit/aliases.json', '--public-base-url' => $base]), $output);
    $plan = $app->make(R2ImageMatcher::class)->plan($objects, $records, $aliases, true);
    $updates = [];
    foreach ($plan as $row) {
        if ($row['status'] !== 'confident') {
            continue;
        }
        $url = $base.'/'.implode('/', array_map('rawurlencode', explode('/', $row['key'])));
        if (PublicImageUrl::safe($url) !== $url) {
            throw new RuntimeException('Rejected public URL');
        }
        if ($row['record']['image_url'] !== null) {
            throw new RuntimeException('Expected empty image_url');
        }
        $updates[] = ['table' => $row['type'], 'id' => $row['record']['id'], 'name' => $row['record']['name'], 'before_image_url' => $row['record']['image_url'], 'r2_key' => $row['key'], 'proposed_image_url' => $url];
    }
    if ($exit !== 0 || count($updates) !== 85 || count(array_filter($plan, fn ($r) => $r['status'] === 'ambiguous')) !== 0) {
        throw new RuntimeException('Unexpected matching result');
    }
    $afterRecords = [];
    foreach (['ingredients' => 'name', 'kitchen_equipment' => 'name', 'recipes' => 'title'] as $table => $name) {
        $afterRecords[$table] = $db->table($table)->orderBy('id')->get(['id', $name.' as name', 'image_url'])->map(fn ($r) => (array) $r)->all();
    }
    $after = hash('sha256', json_encode($afterRecords));
    if ($before !== $after) {
        throw new RuntimeException('Snapshot changed');
    }
    $readOnly = $db->selectOne('SHOW transaction_read_only')->transaction_read_only;
    $db->rollBack();
    echo json_encode(['observed_at_utc' => gmdate('c'), 'exit_code' => $exit, 'transaction_read_only' => $readOnly, 'public_base_url' => $base, 'public_image_hosts' => $hosts, 'catalog_before_sha256' => $before, 'catalog_after_sha256' => $after, 'command_output' => $output->fetch(), 'objects' => $objects, 'records' => $records, 'plan' => $plan, 'proposed_updates' => $updates, 'apply_executed' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit($exit);
} catch (Throwable $e) {
    fwrite(STDERR, 'Final dry-run failed; exception class: '.get_class($e).PHP_EOL);
    exit(2);
}
