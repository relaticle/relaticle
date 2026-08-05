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
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });

        // billing:process-trials sweeps this column daily; the overwhelming
        // majority of rows are null, so index only the ones it looks at.
        DB::statement('create index teams_trial_ends_at_index on teams (trial_ends_at) where trial_ends_at is not null');
    }
};
