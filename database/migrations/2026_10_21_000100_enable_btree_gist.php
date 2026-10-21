<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Бізнес-схема TelmanFit потребує PostgreSQL із btree_gist.');
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    public function down(): void
    {
        // Розширення може існувати до міграції та обслуговувати сторонні обʼєкти.
        // Відкат бізнес-схеми навмисно залишає його встановленим.
    }
};
