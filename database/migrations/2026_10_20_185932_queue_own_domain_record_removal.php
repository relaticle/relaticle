<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::queue('email:remove-own-domain-records', ['--force' => true])
            ->onQueue('imports')
            ->delay(now()->addMinutes(5))
            ->afterCommit();
    }
};
