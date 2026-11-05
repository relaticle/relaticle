<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->timestamp('paused_at')->nullable();
            $table->string('deletion_reason')->nullable();
            $table->timestamp('inactivity_notice_sent_at')->nullable();
        });

        DB::table('workspaces')
            ->whereNotNull('scheduled_deletion_at')
            ->update(['deletion_reason' => 'requested']);

        DB::table('workspaces')
            ->where('plan', 'free')
            ->whereNotNull('pro_trial_used_at')
            ->whereNull('trial_ends_at')
            ->whereNull('hosted_free_grandfathered_at')
            ->whereNotExists(fn (Builder $subscriptions): Builder => $subscriptions
                ->select(DB::raw(1))
                ->from('subscriptions')
                ->whereColumn('subscriptions.workspace_id', 'workspaces.id')
                ->where('subscriptions.type', '!=', 'managed_pending')
                ->whereNotIn('subscriptions.stripe_status', ['incomplete', 'incomplete_expired']))
            ->whereNotExists(fn (Builder $purchases): Builder => $purchases
                ->select(DB::raw(1))
                ->from('ai_credit_transactions')
                ->whereColumn('ai_credit_transactions.workspace_id', 'workspaces.id')
                ->where('ai_credit_transactions.type', 'purchase'))
            ->update(['paused_at' => now()]);
    }
};
