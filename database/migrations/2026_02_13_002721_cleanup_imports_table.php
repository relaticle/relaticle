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
            $table->unsignedInteger('failed_rows')->default(0)->after('skipped_rows');
        });

        Schema::table('imports', function (Blueprint $table): void {
            $table->dropColumn([
                'file_path',
                'importer',
                'processed_rows',
                'successful_rows',
                'results',
                'failed_rows_data',
            ]);
        });
    }
};
