<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Приведення наявної Laravel-таблиці `users` до бізнес-вимог TelmanFit (gate G3, стратегія B + R1).
 *
 * Міграція робить рівно три затверджені зміни й не додає жодної нової сутності:
 *  1) `users.email` і `users.password` стають nullable — BR-U-02 (Telegram-only користувачі без пароля);
 *  2) додається `telegram_user_id bigint NULL UNIQUE` — BR-U-02 (майбутній Stage 8, прив'язка Telegram);
 *  3) додається `role text NOT NULL DEFAULT 'client'` + CHECK (admin|coach|client) — BR-U-01.
 *
 * Джерела: `docs/02_users_clients.sql` (таблиця users), `docs/database.dbml`, `docs/business-rules.md`.
 *
 * Свідомі відхилення від `docs/02_users_clients.sql` (узгоджено в G3, стратегія R1):
 *  - `users.id` залишається BIGINT (`bigserial` зі стандартної міграції
 *    `0001_01_01_000000_create_users_table.php`), а не `integer GENERATED ALWAYS AS IDENTITY`:
 *    стандартну міграцію не редагуємо, а конвертація типу на живій БД вимагала б
 *    `ALTER COLUMN ... TYPE` і перестворення identity зі `START WITH max(id)+1`;
 *  - збережено Laravel-службові колонки `remember_token` (потрібна Filament remember-me)
 *    та `email_verified_at`, яких немає в бізнес-схемі;
 *  - `created_at` / `updated_at` лишаються `timestamp NULL` без DB-default (як у 0001).
 *
 * `NOT NULL` знімається сирим DDL, а не `->nullable()->change()`: у PostgreSQL `change()`
 * переписує тип (`alter column ... type varchar(255)`) і додає `drop default`, тобто ця
 * міграція мовчки зафіксувала б тип колонки та знищила б будь-який майбутній default.
 * `ALTER COLUMN ... DROP NOT NULL` робить рівно одну зміну і сам по собі ідемпотентний.
 *
 * ПІСЛЯ ЗАСТОСУВАННЯ (окремим погодженим кроком, вручну):
 *  наявний користувач Filament отримає `role = 'client'`; роль `admin` міграція НЕ призначає.
 *  Перед увімкненням рольової авторизації Filament потрібно явно виконати, наприклад:
 *  `UPDATE users SET role = 'admin' WHERE id = 1;`
 */
return new class extends Migration
{
    /**
     * Роль за замовчуванням для нових користувачів (BR-U-01).
     */
    private const DEFAULT_ROLE = 'client';

    /**
     * Дозволені ролі (BR-U-01, CHECK у docs/02_users_clients.sql).
     */
    private const ROLES = ['admin', 'coach', 'client'];

    /**
     * Ім'я CHECK-обмеження: збігається з іменем, яке PostgreSQL згенерував би
     * для однойменного CHECK з docs/02_users_clients.sql.
     */
    private const ROLE_CHECK = 'users_role_check';

    /**
     * Ім'я UNIQUE-обмеження для telegram_user_id (конвенція Laravel).
     */
    private const TELEGRAM_UNIQUE = 'users_telegram_user_id_unique';

    /**
     * Застосувати зміни: нові колонки + ослаблення NOT NULL.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->bigInteger('telegram_user_id')->nullable()->unique(self::TELEGRAM_UNIQUE);
            $table->text('role')->default(self::DEFAULT_ROLE);
        });

        DB::statement('ALTER TABLE "users" ALTER COLUMN "email" DROP NOT NULL');
        DB::statement('ALTER TABLE "users" ALTER COLUMN "password" DROP NOT NULL');

        DB::statement($this->roleCheckStatement());
    }

    /**
     * Відкат: повернути схему до стану міграції 0001.
     *
     * Відкат ніколи не втрачає дані мовчки: якщо в таблиці вже є значення, яких не існує
     * у вихідній схемі (роль, відмінна від 'client', або прив'язка Telegram), `down()`
     * зупиняється з поясненням. Кинутий виняток скасовує транзакцію Laravel, тому БД
     * лишається без змін, а міграція — позначеною як застосована.
     */
    public function down(): void
    {
        $this->assertRollbackIsSafe();

        DB::statement('ALTER TABLE "users" DROP CONSTRAINT IF EXISTS "'.self::ROLE_CHECK.'"');

        $columns = array_values(array_filter(
            ['role', 'telegram_user_id'],
            static fn (string $column): bool => Schema::hasColumn('users', $column)
        ));

        if ($columns !== []) {
            Schema::table('users', static function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }

        DB::statement('ALTER TABLE "users" ALTER COLUMN "email" SET NOT NULL');
        DB::statement('ALTER TABLE "users" ALTER COLUMN "password" SET NOT NULL');
    }

    /**
     * Ідемпотентний DDL для CHECK-обмеження ролі (BR-U-01).
     *
     * Створення обмеження — єдина неідемпотентна дія міграції, тому вона захищена
     * перевіркою `pg_constraint` (той самий підхід, що й для `assignment_no_self_replacement`
     * у `tests/pg/README.md`). Існуючі рядки отримали 'client', тому CHECK валідується одразу.
     */
    private function roleCheckStatement(): string
    {
        $allowedRoles = implode(', ', array_map(
            static fn (string $role): string => "'{$role}'",
            self::ROLES
        ));

        return sprintf(
            <<<'SQL'
            DO $x$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conrelid = 'public.users'::regclass
                      AND conname = '%1$s'
                ) THEN
                    EXECUTE $q$ALTER TABLE public.users ADD CONSTRAINT %1$s CHECK (role IN (%2$s))$q$;
                END IF;
            END
            $x$;
            SQL,
            self::ROLE_CHECK,
            $allowedRoles
        );
    }

    /**
     * Перевірка безпеки відкату: жодна зміна, зроблена цією міграцією, не має
     * зникнути непомітно.
     */
    private function assertRollbackIsSafe(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            $nonDefaultRoles = DB::table('users')->where('role', '<>', self::DEFAULT_ROLE)->count();

            if ($nonDefaultRoles > 0) {
                throw new RuntimeException(sprintf(
                    'Відкат скасовано: %d користувач(ів) мають role, відмінну від "%s" (наприклад, призначений admin). '
                    .'Видалення колонки role знищило б цю інформацію. Спочатку явно змініть ролі або виконайте '
                    .'відкат окремою погодженою міграцією.',
                    $nonDefaultRoles,
                    self::DEFAULT_ROLE
                ));
            }
        }

        if (Schema::hasColumn('users', 'telegram_user_id')) {
            $boundTelegramUsers = DB::table('users')->whereNotNull('telegram_user_id')->count();

            if ($boundTelegramUsers > 0) {
                throw new RuntimeException(sprintf(
                    'Відкат скасовано: %d користувач(ів) мають прив\'язку telegram_user_id. '
                    .'Видалення колонки знищило б прив\'язку до Telegram. Спочатку явно очистіть ці значення.',
                    $boundTelegramUsers
                ));
            }
        }

        $nullEmails = DB::table('users')->whereNull('email')->count();
        $nullPasswords = DB::table('users')->whereNull('password')->count();

        if ($nullEmails > 0 || $nullPasswords > 0) {
            throw new RuntimeException(sprintf(
                'Відкат скасовано: email IS NULL — %d, password IS NULL — %d. Повернення NOT NULL '
                .'неможливе, доки ці значення не заповнені (Telegram-only користувачі).',
                $nullEmails,
                $nullPasswords
            ));
        }
    }
};
