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
        Schema::table('ai_credit_balances', function (Blueprint $table): void {
            $table->unsignedInteger('purchased_credits')->default(0);
        });

        DB::statement('ALTER TABLE ai_credit_balances ADD CONSTRAINT ai_credit_balances_purchased_nonneg CHECK (purchased_credits >= 0)');
        DB::statement('ALTER TABLE ai_credit_balances ADD CONSTRAINT ai_credit_balances_purchased_lte_remaining CHECK (purchased_credits <= credits_remaining)');
    }
};
