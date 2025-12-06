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
        Schema::table('imports', function (Blueprint $table): void {
            $table->json('column_mappings')->nullable()->after('importer');
            $table->string('duplicate_strategy')->nullable()->after('column_mappings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->dropColumn(['column_mappings', 'duplicate_strategy']);
        });
    }
};
