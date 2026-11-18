<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_folders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['user_id', 'workspace_id']);
        });

        Schema::create('favorite_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('record_type');
            $table->ulid('record_id');
            $table->foreignUlid('favorite_folder_id')->nullable()->constrained('favorite_folders')->nullOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['record_type', 'record_id']);
            $table->unique(['user_id', 'workspace_id', 'record_type', 'record_id']);
        });
    }
};
