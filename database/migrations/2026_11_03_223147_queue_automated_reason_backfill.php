<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        // The cutoff sits at the job's start, so mail a pre-deploy worker stored without a reason is read too.
        Artisan::queue('email:backfill-automated-reasons', [
            '--force' => true,
            '--before' => now()->addMinutes(5)->toIso8601String(),
        ])->onQueue('imports')->delay(now()->addMinutes(5))->afterCommit();
    }
};
