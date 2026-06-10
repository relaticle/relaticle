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
        Schema::create('ai_credit_balances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('credits_remaining')->default(0);
            $table->unsignedInteger('credits_used')->default(0);
            $table->timestamp('period_starts_at');
            $table->timestamp('period_ends_at');
            $table->timestamps();

            $table->unique('team_id');
        });

        DB::statement('ALTER TABLE ai_credit_balances ADD CONSTRAINT ai_credit_balances_credits_nonneg CHECK (credits_remaining >= 0)');
        DB::statement('ALTER TABLE ai_credit_balances ADD CONSTRAINT ai_credit_balances_credits_used_nonneg CHECK (credits_used >= 0)');
        DB::statement('ALTER TABLE ai_credit_balances ADD CONSTRAINT ai_credit_balances_period_order CHECK (period_starts_at < period_ends_at)');
    }
};
