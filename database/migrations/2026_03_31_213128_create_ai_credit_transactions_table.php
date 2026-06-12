<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('conversation_id', 36)->nullable();
            $table->string('idempotency_key');
            $table->string('type');
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('credits_charged')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['team_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('conversation_id');
            $table->index(['team_id', 'user_id', 'created_at']);

            $table->unique(['team_id', 'idempotency_key']);

            $table->foreign('conversation_id')->references('id')->on('agent_conversations')->nullOnDelete();
        });

        DB::statement('ALTER TABLE ai_credit_transactions ADD CONSTRAINT ai_credit_transactions_input_tokens_nonneg CHECK (input_tokens >= 0)');
        DB::statement('ALTER TABLE ai_credit_transactions ADD CONSTRAINT ai_credit_transactions_output_tokens_nonneg CHECK (output_tokens >= 0)');
        DB::statement('ALTER TABLE ai_credit_transactions ADD CONSTRAINT ai_credit_transactions_credits_charged_nonneg CHECK (credits_charged >= 0)');
    }
};
