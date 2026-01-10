<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables that need the creation_source column.
     *
     * @var array<string>
     */
    private array $tables = [
        'companies',
        'people',
        'opportunities',
        'tasks',
        'notes',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->after('creator_id', function (Blueprint $table): void {
                    $table->string('creation_source', 50);
                });
            });
        }

        // Set default value for existing records
        foreach ($this->tables as $table) {
            DB::table($table)->update(['creation_source' => CreationSource::WEB->value]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('creation_source');
            });
        }
    }
};
