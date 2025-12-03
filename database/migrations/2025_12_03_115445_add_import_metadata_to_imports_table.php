<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->json('column_mappings')->nullable()->after('importer');
            $table->string('duplicate_strategy')->nullable()->after('column_mappings');
            $table->uuid('migration_batch_id')->nullable()->after('duplicate_strategy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn(['column_mappings', 'duplicate_strategy', 'migration_batch_id']);
        });
    }
};
