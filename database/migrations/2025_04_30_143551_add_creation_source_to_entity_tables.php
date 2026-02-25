<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        'companies',
        'people',
        'opportunities',
        'tasks',
        'notes',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'creation_source')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('creation_source', 50);
            });
        }

        foreach ($this->tables as $table) {
            DB::table($table)->whereNull('creation_source')->update(['creation_source' => CreationSource::WEB->value]);
        }
    }
};
