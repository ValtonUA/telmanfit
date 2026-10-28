<?php

use App\Models\User;
use App\Services\Cooking\CookingTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Recipes\BatchFixture;

try {
    require __DIR__.'/auth2-bootstrap.php';
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
if (($argv[1] ?? '') === 'failure-probe') {
    throw new RuntimeException('Cooking failure propagation probe');
}
// Кожен worker — окремий PHP-процес і PostgreSQL backend, лише PDO з наявного runtime.
if (($argv[1] ?? '') === 'worker') {
    $pdo = DB::connection()->getPdo();
    while (($line = fgets(STDIN)) !== false) {
        $sql = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if ($sql === null) {
            exit(0);
        }
        try {
            $rows = [];
            if (is_array($sql)) {
                $service = app(CookingTemplates::class);
                $actor = User::findOrFail($sql['actor']);
                if ($sql['operation'] === 'review') {
                    $service->review($actor, $sql['v'], $sql['revision'], array_fill_keys(['suitability', 'safety', 'manual_parameters', 'no_process_water_tokens'], true));
                } else {
                    $service->publish($actor, $sql['v'], $sql['revision']);
                }
            } else {
                // Protocol приймає лише наведені нижче test SQL без ; у literals.
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $command) {
                    $statement = $pdo->query($command);
                    if ($statement->columnCount() > 0) {
                        $rows = $statement->fetchAll(PDO::FETCH_NUM);
                    }
                }
            }
            $reply = ['rows' => $rows, 'states' => []];
        } catch (PDOException $e) {
            $reply = ['rows' => [], 'states' => [$e->errorInfo[0] ?? $e->getCode()], 'error' => $e->getMessage()];
        } catch (HttpException $e) {
            $reply = ['rows' => [], 'states' => ['HTTP'.$e->getStatusCode()]];
        }
        fwrite(STDOUT, json_encode($reply, JSON_THROW_ON_ERROR)."\n");
        fflush(STDOUT);
    }
    exit(0);
}

final class CookingConnection
{
    private $process;

    private array $pipes = [];

    private bool $pending = false;

    public function __construct()
    {
        $this->process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $this->pipes);
        if (! is_resource($this->process)) {
            throw new RuntimeException('Не запущено незалежний PDO worker.');
        }
        stream_set_timeout($this->pipes[1], 15);
    }

    public function send(string|array $sql): void
    {
        cookingCheck(! $this->pending, 'Worker уже виконує query.');
        $this->pending = true;
        $message = json_encode($sql, JSON_THROW_ON_ERROR)."\n";
        cookingCheck(fwrite($this->pipes[0], $message) === strlen($message), 'Не надіслано SQL worker.');
        fflush($this->pipes[0]);
    }

    public function result(): array
    {
        cookingCheck($this->pending, 'Немає pending query.');
        $line = fgets($this->pipes[1]);
        cookingCheck($line !== false, 'Worker timeout або failure.');
        $this->pending = false;

        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }

    public function close(): void
    {
        if ($this->pending) {
            $this->result();
        }
        $this->send('ROLLBACK');
        $this->result();
        fwrite($this->pipes[0], "null\n");
        fclose($this->pipes[0]);
        $errors = stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        cookingCheck(proc_close($this->process) === 0, 'PDO worker failure: '.$errors);
    }
}

// Guard вище перевіряє socket/identity/PG18 до будь-яких DDL. Лише disposable БД.
foreach (['cooking_template_versions' => '2026_10_28_000000_create_cooking_templates.php', 'cooking_selections' => '2026_10_28_010000_add_cooking_selection.php'] as $table => $migration) {
    if (! Schema::hasTable($table)) {
        (require database_path('migrations/'.$migration))->up();
    }
}
$fixture = new class
{
    use BatchFixture;

    public function make(?array $previous = null): array
    {
        return DB::transaction(function () use ($previous): array {
            if ($previous === null) {
                $actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic cooking race', 'role' => 'admin']));
                $batch = $this->batch();
                $this->publish($batch['version']);
                $type = DB::table('meal_types')->insertGetId(['code' => 'cooking-'.bin2hex(random_bytes(5)), 'title' => 'Synthetic']);
                $unit = DB::table('uoms')->where('code', 'serving')->value('id') ?? DB::table('uoms')->insertGetId(['code' => 'serving', 'label' => 'порція', 'kind' => 'serving']);
                $plan = DB::table('meal_plans')->insertGetId(['title' => 'Synthetic cooking race']);
                $source = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
                foreach (range(1, 7) as $n) {
                    $day = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $source, 'day_number' => $n]);
                    $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $day, 'meal_type_id' => $type]);
                    DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => DB::table('recipe_versions')->where('id', $batch['version'])->value('food_id'),
                        'quantity' => '0.50', 'uom_id' => $unit, 'recipe_version_id' => $batch['version'], 'recipe_batch_format_id' => $batch['formats']['week'], 'recipe_portion_divisor' => 7]);
                }
                DB::table('meal_plan_versions')->where('id', $source)->update(['status' => 'published', 'published_at' => now(), 'version_number' => 1]);
                $template = DB::table('cooking_templates')->insertGetId(['title' => 'Synthetic race', 'created_by' => $actor->id]);
                $number = 1;
            } else {
                extract($previous, EXTR_SKIP);
                $number++;
            }
            $v = DB::table('cooking_template_versions')->insertGetId(['template_id' => $template, 'version_number' => $number, 'previous_version_id' => $previous['v'] ?? null,
                'source_plan_version_id' => $source, 'source_week' => 1, 'mode' => 1]);
            $session = DB::table('cooking_sessions')->insertGetId(['version_id' => $v, 'position' => 1, 'day' => 1, 'estimated_seconds' => 10,
                'content' => json_encode(['schema_version' => 1, 'stages' => [['id' => 's', 'title' => 'Synthetic', 'blocks' => [['id' => 'a', 'type' => 'action', 'parts' => ['Synthetic instruction']]]]]])]);
            $output = DB::table('cooking_outputs')->insertGetId(['version_id' => $v, 'session_id' => $session, 'recipe_version_id' => $batch['version'], 'format_id' => $batch['formats']['week'], 'portion_divisor' => 7]);
            foreach (range(1, 7) as $day) {
                DB::table('cooking_allocations')->insert(['version_id' => $v, 'output_id' => $output, 'day' => $day, 'meal_position' => 1, 'item_position' => 1, 'portions' => '0.50']);
            }

            return compact('actor', 'batch', 'source', 'template', 'number', 'v', 'session', 'output');
        });
    }
};

function cookingCheck(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}
function cookingQuery(CookingConnection $connection, string $sql): array
{
    $connection->send($sql);
    $result = $connection->result();
    cookingCheck($result['states'] === [], 'Неочікувана PG помилка: '.($result['error'] ?? ''));

    return $result;
}
function cookingFetch(array $result, int $row, int $column): mixed
{
    return $result['rows'][$row][$column];
}
function cookingWait(CookingConnection $connection, int $pid): void
{
    $deadline = microtime(true) + 5;
    do {
        cookingQuery($connection, 'SELECT pg_stat_clear_snapshot()');
        $r = cookingQuery($connection, "SELECT coalesce(wait_event_type='Lock',false) FROM pg_stat_activity WHERE pid=$pid");
        if (cookingFetch($r, 0, 0) === true) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Не доведено реальне очікування PG lock.');
}
function cookingSend(CookingConnection $connection, string|array $sql): bool
{
    $connection->send($sql);

    return true;
}
function cookingResults(CookingConnection $connection): array
{
    return $connection->result()['states'];
}
function cookingReviewSql(array $d, int $revision): string
{
    return "SELECT cooking_assert_revision({$d['v']},$revision); SELECT cooking_assert_version({$d['v']}); UPDATE cooking_template_versions SET reviewed_revision=revision,reviewed_by={$d['actor']->id},reviewed_at=now() WHERE id={$d['v']}";
}
function cookingRevision(array $d): int
{
    return (int) DB::table('cooking_template_versions')->where('id', $d['v'])->value('revision');
}
function cookingPublish(array $d): void
{
    $service = app(CookingTemplates::class);
    $service->review($d['actor'], $d['v'], cookingRevision($d), array_fill_keys(['suitability', 'safety', 'manual_parameters', 'no_process_water_tokens'], true));
    $service->publish($d['actor'], $d['v'], cookingRevision($d));
}

$connections = [];
$passed = 0;
try {
    foreach (range(1, 3) as $n) {
        $c = new CookingConnection;
        cookingCheck($c !== false, 'Потрібне незалежне PG з’єднання.');
        $connections[] = $c;
        cookingQuery($c, "SET statement_timeout='12s'; SET lock_timeout='8s'");
    }
    [$a, $b, $observer] = $connections;
    $pid = (int) cookingFetch(cookingQuery($b, 'SELECT pg_backend_pid()'), 0, 0);
    cookingCheck(count(array_unique(array_map(fn ($c) => cookingFetch(cookingQuery($c, 'SELECT pg_backend_pid()'), 0, 0), $connections))) === 3, 'Sessions повинні бути незалежні.');
    $race = function (string $name, string $first, string|array $second, array $expected = [], bool $commit = true, ?callable $during = null) use ($a, $b, $observer, $pid, &$passed): void {
        cookingQuery($a, 'BEGIN');
        if (is_string($second)) {
            cookingQuery($b, 'BEGIN');
        }
        cookingQuery($a, $first);
        cookingCheck(cookingSend($b, $second), 'Async query не надіслано.');
        cookingWait($observer, $pid);
        if ($during !== null) {
            $during();
        }
        cookingQuery($a, $commit ? 'COMMIT' : 'ROLLBACK');
        $states = cookingResults($b);
        cookingCheck($expected === [] ? $states === [] : count($states) === 1 && in_array($states[0], $expected, true), "$name: неправильний SQLSTATE ".json_encode($states));
        if (is_string($second)) {
            cookingQuery($b, $states === [] ? 'COMMIT' : 'ROLLBACK');
        }
        $passed++;
        echo "Cooking concurrency: $name PASS\n";
    };

    foreach (['review', 'publish'] as $operation) {
        $d = $fixture->make();
        $rev = cookingRevision($d);
        cookingQuery($a, cookingReviewSql($d, $rev));
        $race("service edit-before-$operation", "UPDATE cooking_sessions SET equipment='service race' WHERE id={$d['session']}",
            ['operation' => $operation, 'actor' => $d['actor']->id, 'v' => $d['v'], 'revision' => $rev], ['HTTP409']);
        cookingCheck(DB::table('cooking_template_versions')->where('id', $d['v'])->value('status') === 'draft', 'Service race partial publish.');
    }

    foreach (['review', 'publish'] as $operation) {
        $d = $fixture->make();
        $rev = cookingRevision($d);
        $race("review-before-service-$operation", cookingReviewSql($d, $rev),
            ['operation' => $operation, 'actor' => $d['actor']->id, 'v' => $d['v'], 'revision' => $rev]);
        cookingCheck(DB::table('cooking_template_versions')->where('id', $d['v'])->value('status') === ($operation === 'publish' ? 'published' : 'draft'), 'Service не завершив очікуваний перехід.');
    }

    $d = $fixture->make();
    $rev = cookingRevision($d);
    $race('failed-publication-rolls-back-all-detail-writes', "SELECT cooking_assert_revision({$d['v']},$rev)",
        "UPDATE cooking_sessions SET equipment='must rollback' WHERE id={$d['session']}; UPDATE cooking_allocations SET portions=100 WHERE version_id={$d['v']} AND day=1; UPDATE cooking_template_versions SET reviewed_revision=revision,reviewed_by={$d['actor']->id},reviewed_at=now() WHERE id={$d['v']}; UPDATE cooking_template_versions SET status='published',published_at=now() WHERE id={$d['v']}", ['P0001']);
    cookingCheck(cookingRevision($d) === $rev, 'Failed publish залишив revision.');
    cookingCheck(DB::table('cooking_sessions')->where('id', $d['session'])->value('equipment') === '', 'Failed publish залишив detail write.');
    cookingCheck(DB::table('cooking_allocations')->where('version_id', $d['v'])->where('day', 1)->value('portions') === '0.50', 'Failed publish залишив allocation.');
    cookingCheck(DB::table('cooking_template_versions')->where('id', $d['v'])->value('reviewed_revision') === null, 'Failed publish залишив review.');
    cookingCheck(DB::table('cooking_template_versions')->where('id', $d['v'])->value('status') === 'draft', 'Partial publication.');

    foreach (['session', 'output', 'allocation'] as $detail) {
        $d = $fixture->make();
        $rev = cookingRevision($d);
        $edit = match ($detail) {
            'session' => "UPDATE cooking_sessions SET equipment='second writer' WHERE id={$d['session']}",
            'output' => "UPDATE cooking_outputs SET portion_divisor=portion_divisor WHERE id={$d['output']}",
            'allocation' => "UPDATE cooking_allocations SET portions=portions WHERE version_id={$d['v']} AND day=1",
        };
        $race("detail-vs-$detail", "UPDATE cooking_sessions SET introduction='first writer' WHERE id={$d['session']}", $edit);
        cookingCheck(cookingRevision($d) === $rev + 2, 'Втрачено detail revision.');
    }

    foreach (['session', 'output', 'allocation'] as $detail) {
        foreach (['review', 'publish'] as $operation) {
            $d = $fixture->make();
            $rev = cookingRevision($d);
            $review = cookingReviewSql($d, $rev);
            cookingQuery($a, $review);
            $edit = match ($detail) {
                'session' => "UPDATE cooking_sessions SET equipment='racing edit' WHERE id={$d['session']}",
                'output' => "UPDATE cooking_outputs SET portion_divisor=portion_divisor WHERE id={$d['output']}",
                'allocation' => "UPDATE cooking_allocations SET portions=portions WHERE version_id={$d['v']} AND day=1",
            };
            $publish = "SELECT cooking_assert_revision({$d['v']},$rev); UPDATE cooking_template_versions SET status='published',published_at=now() WHERE id={$d['v']}";
            $race("$detail edit-before-$operation", $edit, $operation === 'review' ? $review : $publish, ['40001']);
            cookingCheck(DB::table('cooking_template_versions')->where('id', $d['v'])->value('status') === 'draft', 'Неочікуваний partial publish.');
            cookingCheck(DB::table('cooking_template_versions')->where('id', $d['v'])->value('reviewed_revision') === null, 'Stale review зберігся.');
            $rev = cookingRevision($d);
            $race("$detail rollback-before-review", $edit, cookingReviewSql($d, $rev), [], false);
            cookingCheck(cookingRevision($d) === $rev, 'Rollback змінив revision.');
            $race("$detail publish-before-edit", "SELECT cooking_assert_revision({$d['v']},$rev); UPDATE cooking_template_versions SET status='published',published_at=now() WHERE id={$d['v']}", $edit, ['P0001'], true,
                function () use ($d): void {
                    cookingCheck(DB::table('cooking_template_versions')->where('id', $d['v'])->value('status') === 'draft', 'Uncommitted publish видимий читачеві.');
                });
            cookingCheck(DB::table('cooking_template_versions')->where('id', $d['v'])->value('status') === 'published', 'Publish не завершився.');
            DB::select('SELECT cooking_assert_version(?)', [$d['v']]);
        }
    }

    $d = $fixture->make();
    $rev = cookingRevision($d);
    $race('review-before-edit', cookingReviewSql($d, $rev), "UPDATE cooking_sessions SET equipment='after review' WHERE id={$d['session']}");
    cookingCheck(DB::table('cooking_template_versions')->where('id', $d['v'])->value('reviewed_revision') === null, 'Edit не скинув review.');
    $rev = cookingRevision($d);
    cookingQuery($a, cookingReviewSql($d, $rev));
    $publish = "SELECT cooking_assert_revision({$d['v']},$rev); UPDATE cooking_template_versions SET status='published',published_at=now() WHERE id={$d['v']}";
    $race('double-publish', $publish, $publish, ['40001']);

    // Два одночасні create-next: рівно один новий draft, без пропущених номерів.
    $create = "INSERT INTO cooking_template_versions(template_id,previous_version_id,version_number,source_plan_version_id,source_week,mode) VALUES({$d['template']},{$d['v']},2,{$d['source']},1,1)";
    $race('create-next-version', $create, $create, ['P0001', '23505']);
    cookingCheck(DB::table('cooking_template_versions')->where('template_id', $d['template'])->where('status', 'draft')->count() === 1, 'Має бути один draft.');

    foreach (['READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'] as $isolation) {
        $d = $fixture->make();
        $rev = cookingRevision($d);
        cookingQuery($a, 'BEGIN');
        cookingQuery($b, "BEGIN ISOLATION LEVEL $isolation");
        cookingQuery($b, "SELECT revision FROM cooking_template_versions WHERE id={$d['v']}");
        cookingQuery($a, "UPDATE cooking_sessions SET equipment='winner' WHERE id={$d['session']}");
        cookingSend($b, "SELECT cooking_assert_revision({$d['v']},$rev); UPDATE cooking_sessions SET equipment='loser' WHERE id={$d['session']}");
        cookingWait($observer, $pid);
        cookingQuery($a, 'COMMIT');
        cookingCheck(cookingResults($b) === ['40001'], "$isolation повинен відхилити stale intent.");
        cookingQuery($b, 'ROLLBACK');
        cookingCheck(DB::table('cooking_sessions')->where('id', $d['session'])->value('equipment') === 'winner', 'Lost update.');
        $passed++;
        echo "Cooking concurrency: $isolation revision/snapshot PASS\n";
    }

    $d = $fixture->make();
    cookingPublish($d);
    $next = $fixture->make($d);
    cookingPublish($next);
    $actor = $d['actor']->id;
    $race('first explicit selection CAS', "SELECT cooking_select($actor,{$d['v']},0)", "SELECT cooking_select($actor,{$next['v']},0)", ['40001']);
    $race('replacement rollback', "SELECT cooking_select($actor,{$next['v']},1)", "SELECT cooking_select($actor,{$next['v']},1)", [], false);
    cookingCheck((int) DB::table('cooking_selections')->where('template_id', $d['template'])->value('version_id') === $next['v'], 'Replacement не завершився.');
    $race('replacement ABA CAS', "SELECT cooking_select($actor,{$d['v']},2); SELECT cooking_select($actor,{$next['v']},3)", "SELECT cooking_select($actor,{$d['v']},2)", ['40001']);

    // MVCC: запит до switch читає стару цілісну selection; новий snapshot — нову.
    cookingQuery($observer, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
    $old = cookingFetch(cookingQuery($observer, "SELECT version_id FROM cooking_selections WHERE template_id={$d['template']}"), 0, 0);
    app(CookingTemplates::class)->select($d['actor'], $d['v'], 4);
    cookingCheck(cookingFetch(cookingQuery($observer, "SELECT version_id FROM cooking_selections WHERE template_id={$d['template']}"), 0, 0) === $old, 'Mixed read snapshot.');
    cookingQuery($observer, 'COMMIT');
    cookingCheck((int) cookingFetch(cookingQuery($observer, "SELECT version_id FROM cooking_selections WHERE template_id={$d['template']}"), 0, 0) === $d['v'], 'Новий snapshot повернув стару selection.');
    $passed++;

    // Справжній deadlock між raw child-first writer і aggregate root-first writer.
    $d = $fixture->make();
    cookingQuery($a, 'BEGIN');
    cookingQuery($b, 'BEGIN');
    cookingQuery($a, "SELECT cooking_assert_revision({$d['v']},".cookingRevision($d).')');
    cookingQuery($b, "SELECT id FROM cooking_sessions WHERE id={$d['session']} FOR UPDATE");
    cookingSend($b, "UPDATE cooking_sessions SET equipment='deadlock b' WHERE id={$d['session']}");
    cookingWait($observer, $pid);
    cookingSend($a, "UPDATE cooking_sessions SET equipment='deadlock a' WHERE id={$d['session']}");
    // Один victim розблоковує survivor; обидва rollback зберігають aggregate.
    $statesA = cookingResults($a);
    cookingQuery($a, 'ROLLBACK');
    $statesB = cookingResults($b);
    cookingQuery($b, 'ROLLBACK');
    cookingCheck(array_merge($statesA, $statesB) === ['40P01'], 'Потрібний справжній deadlock victim 40P01.');
    cookingCheck(DB::table('cooking_sessions')->where('id', $d['session'])->value('equipment') === '', 'Deadlock залишив часткові зміни.');
    $passed++;
    echo "Cooking concurrency: $passed scenarios PASS; independent PG18 sessions, observed locks, CAS, snapshots, deadlock rollback.\n";
} finally {
    foreach ($connections as $connection) {
        $connection->close();
    }
}
