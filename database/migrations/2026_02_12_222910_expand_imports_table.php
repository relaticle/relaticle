<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->string('entity_type')->nullable()->after('user_id');
            $table->string('status')->default('uploading')->after('entity_type');

            $table->json('headers')->nullable()->after('status');
            $table->json('column_mappings')->nullable()->after('headers');
            $table->json('results')->nullable()->after('column_mappings');
            $table->json('failed_rows_data')->nullable()->after('results');

            $table->unsignedInteger('created_rows')->default(0)->after('successful_rows');
            $table->unsignedInteger('updated_rows')->default(0)->after('created_rows');
            $table->unsignedInteger('skipped_rows')->default(0)->after('updated_rows');

            $table->string('file_path')->nullable()->change();
            $table->string('importer')->nullable()->change();
        });
    }
};
