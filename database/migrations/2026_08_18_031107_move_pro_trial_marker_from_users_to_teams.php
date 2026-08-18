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
        Schema::table('teams', function (Blueprint $table): void {
            $table->timestamp('pro_trial_used_at')->nullable();
        });

        // A team mid-trial must not become eligible for a second trial once
        // this one expires. Teams whose trial already expired carry no marker
        // (trial_ends_at was nulled on expiry) and deliberately regain one
        // trial under the per-workspace policy.
        DB::table('teams')
            ->whereNotNull('trial_ends_at')
            ->update(['pro_trial_used_at' => now()]);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('pro_trial_used_at');
        });
    }
};
