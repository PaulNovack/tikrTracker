<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ExportTableSeederCommand extends Command
{
    protected $signature = 'database:export-seeder
        {table : The database table to export}
        {--class= : Optional seeder class name override}
        {--output= : Optional output path override}
        {--truncate : Truncate the table before inserting rows in the generated seeder}
        {--chunk=500 : Insert rows in chunks of this size}
    ';

    protected $description = 'Generate a complete seeder class from the current contents of a database table';

    public function handle(): int
    {
        $table = trim((string) $this->argument('table'));
        if ($table === '') {
            $this->error('A table name is required.');

            return self::FAILURE;
        }

        $className = $this->resolveClassName($table, (string) $this->option('class'));
        $outputPath = $this->resolveOutputPath($className, (string) $this->option('output'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $truncate = (bool) $this->option('truncate');

        if (! DB::getSchemaBuilder()->hasTable($table)) {
            $this->error("Table [{$table}] does not exist.");

            return self::FAILURE;
        }

        $columns = DB::getSchemaBuilder()->getColumnListing($table);
        $rows = DB::table($table)->get();

        if ($rows->isEmpty()) {
            $this->warn("Table [{$table}] has no rows. A seeder file will still be created, but it will insert nothing.");
        }

        $seeder = $this->buildSeederClass(
            className: $className,
            table: $table,
            columns: $columns,
            rows: $rows,
            chunkSize: $chunkSize,
            truncate: $truncate,
        );

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, $seeder);

        $this->info("Seeder written to {$outputPath}");
        $this->line('Class: '.$className);
        $this->line('Rows exported: '.$rows->count());

        return self::SUCCESS;
    }

    private function resolveClassName(string $table, string $override): string
    {
        $base = $override !== '' ? $override : Str::studly(Str::singular($table)).'Seeder';

        return Str::finish($base, 'Seeder');
    }

    private function resolveOutputPath(string $className, string $override): string
    {
        if ($override !== '') {
            return $override;
        }

        return database_path('seeders/'.$className.'.php');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function buildSeederClass(string $className, string $table, array $columns, $rows, int $chunkSize, bool $truncate): string
    {
        $exports = $rows->map(function (object $row) use ($columns): array {
            $payload = [];

            foreach ($columns as $column) {
                $payload[$column] = $this->normalizeValue($row->{$column} ?? null);
            }

            return $payload;
        })->all();

        $chunks = array_chunk($exports, $chunkSize);
        $chunkBlocks = [];

        foreach ($chunks as $chunk) {
            $chunkBlocks[] = '        DB::table('.var_export($table, true).')->insert('.$this->exportValue($chunk, 8).');';
        }

        if (empty($chunkBlocks)) {
            $chunkBlocks[] = '        // No rows were present at generation time.';
        }

        $truncateBlock = $truncate ? "        DB::table('{$table}')->truncate();\n\n" : '';

        $insertBlock = implode("\n", $chunkBlocks);

        return <<<PHP
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class {$className} extends Seeder
{
    public function run(): void
    {
{$truncateBlock}{$insertBlock}
    }
}

PHP;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return $value;
    }

    private function exportValue(mixed $value, int $indentLevel = 0): string
    {
        $export = var_export($value, true);
        $indent = str_repeat(' ', $indentLevel);

        return preg_replace('/^/m', $indent, $export) ?? $export;
    }
}
