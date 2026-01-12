<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        // Allow long-running migration
        set_time_limit(0);
        ini_set('memory_limit', '2048M');

        //
        Artisan::call('custom-fields:upgrade', [
            '--force' => true,
            '--skip' => 'clear-caches',
        ]);
    }
};
