<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUlidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableUlidMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'idx_activity_log_subject_timeline');
            $table->index(['team_id', 'created_at'], 'idx_activity_log_team_activity');
        });
    }
};
