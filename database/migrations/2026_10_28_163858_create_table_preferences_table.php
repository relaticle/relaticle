<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity');
            $table->jsonb('state');
            $table->timestamps();

            $table->unique(['user_id', 'workspace_id', 'entity']);
            $table->index('workspace_id');
        });
    }
};
