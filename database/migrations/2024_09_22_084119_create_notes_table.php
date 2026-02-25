<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('creator_id')->nullable()->constrained('users')->onDelete('set null');

            $table->string('title');
            $table->string('creation_source', 50);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'deleted_at', 'creation_source', 'created_at'], 'idx_notes_team_activity');
        });
    }
};
