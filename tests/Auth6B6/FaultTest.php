<?php

namespace Tests\Auth6B6;

use App\Services\Auth\Budgets\BudgetKey;
use App\Services\Auth\Budgets\BudgetScope;
use App\Services\Auth\Budgets\BudgetUnavailable;
use App\Services\Auth\Budgets\PostgresBudgets;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FaultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
    }

    private function closed(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Budget opened during fault');
        } catch (BudgetUnavailable $e) {
            $this->assertSame('rate_budget_unavailable', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('canary', (string) $e);
        }
    }

    public function test_real_connection_outage_and_persistent_connection_refused(): void
    {
        $key = new BudgetKey(BudgetScope::OtpCooldown, bin2hex(random_bytes(16)));
        $connection = DB::connection();
        $original = $connection->getConfig('host');
        $property = new \ReflectionProperty($connection, 'config');
        $baseConfig = $connection->getConfig();
        $property->setValue($connection, array_replace($baseConfig, ['host' => '/tmp/b6-missing-synthetic-socket']));
        try {
            $this->closed(fn () => app(PostgresBudgets::class)->admit([$key]));
        } finally {
            $property->setValue($connection, $baseConfig);
        }
        $original = $connection->getConfig('options');
        $property->setValue($connection, array_replace($baseConfig, ['options' => [\PDO::ATTR_PERSISTENT => true]]));
        try {
            $this->closed(fn () => app(PostgresBudgets::class)->admit([$key]));
        } finally {
            $property->setValue($connection, $baseConfig);
        }
        $this->assertFalse(DB::table('auth_rate_budgets')->where('key_hash', $key->hash)->exists());
    }

    public function test_real_statement_timeout_no_append_or_local_fallback(): void
    {
        $key = new BudgetKey(BudgetScope::OtpCooldown, bin2hex(random_bytes(16)));
        DB::unprepared('CREATE FUNCTION b6_slow_insert() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(4); RETURN NEW; END $$; CREATE TRIGGER b6_slow BEFORE INSERT ON auth_rate_budgets FOR EACH ROW EXECUTE FUNCTION b6_slow_insert()');
        try {
            $this->closed(fn () => app(PostgresBudgets::class)->admit([$key]));
            $this->assertFalse(DB::table('auth_rate_budgets')->where('key_hash', $key->hash)->exists());
        } finally {
            DB::unprepared('DROP TRIGGER b6_slow ON auth_rate_budgets; DROP FUNCTION b6_slow_insert()');
        }
        $this->assertTrue(app(PostgresBudgets::class)->admit([$key])->allowed);
    }

    public function test_commit_response_ambiguity_real_pg_commit_is_closed_and_not_refunded(): void
    {
        $key = new BudgetKey(BudgetScope::OtpCooldown, bin2hex(random_bytes(16)));
        $original = app('db.factory');
        // Справжній незалежний PG connection; transport-result fault лише після real COMMIT.
        app()->instance('db.factory', new class(app()) extends ConnectionFactory
        {
            public function make(array $config, $name = null)
            {
                $connection = parent::make($config, $name);
                $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];
                $pdo = new class($dsn, $config['username'], $config['password']) extends \PDO
                {
                    public function commit(): bool
                    {
                        parent::commit();
                        throw new \RuntimeException('synthetic-canary-ambiguous-commit');
                    }
                };
                $connection->setPdo($pdo);

                return $connection;
            }
        });
        try {
            $this->closed(fn () => app(PostgresBudgets::class)->admit([$key]));
        } finally {
            app()->instance('db.factory', $original);
        }
        $this->assertSame(1, DB::selectOne('SELECT cardinality(events) n FROM auth_rate_budgets WHERE key_hash=?', [$key->hash])->n);
        $this->assertFalse(app(PostgresBudgets::class)->admit([$key])->allowed);
    }
}
