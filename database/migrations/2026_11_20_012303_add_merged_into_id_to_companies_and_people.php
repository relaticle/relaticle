<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['companies', 'people'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->ulid('merged_into_id')->nullable();
            });
        }
    }
};
