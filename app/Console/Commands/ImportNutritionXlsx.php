<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Imports\LocalNutritionImporter;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ImportNutritionXlsx extends Command
{
    protected $signature = 'nutrition:import-xlsx {file : Шлях до перевіреного XLSX}
        {--recipe= : Локальний recipe_versions.id з Excel}
        {--apply : Записати каталоги й одну чернетку}
        {--actor= : Справжній ID локального admin}
        {--expect-sha256= : SHA256 із попереднього dry-run}';

    protected $description = 'Local-only імпорт Excel-v1: dry-run за замовчуванням, без публікації';

    public function handle(LocalNutritionImporter $importer): int
    {
        try {
            $version = (string) $this->option('recipe');
            if ($version === '') {
                throw new \RuntimeException('Оберіть локальний ID версії через --recipe.');
            }
            if ($this->option('apply')) {
                if (! $this->option('actor') || ! preg_match('/^[a-f0-9]{64}$/D', (string) $this->option('expect-sha256'))) {
                    throw new \RuntimeException('Apply потребує --actor та --expect-sha256 із dry-run.');
                }
                $actor = User::findOrFail($this->option('actor'));
                $result = $importer->apply($this->argument('file'), $version, $this->option('expect-sha256'), $actor);
            } else {
                $result = $importer->inspect($this->argument('file'), $version);
            }
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $message = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
            $this->error($message);

            return self::FAILURE;
        }
    }
}
