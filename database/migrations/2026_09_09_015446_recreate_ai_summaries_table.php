<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreate ai_summaries after 2026_07_30_120000 dropped it.
 *
 * That drop has already run on existing installations. Removing it from
 * the repo does not restore the table. Thread summary generation and
 * SubscriberProfileDeriver::hasAiUsage() query this table after upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_summaries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->ulidMorphs('summarizable');
            $table->text('summary');
            $table->string('input_hash', 64)->nullable();
            $table->string('model_used');
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->timestamps();

            $table->unique(['summarizable_type', 'summarizable_id', 'team_id']);
        });
    }
};
