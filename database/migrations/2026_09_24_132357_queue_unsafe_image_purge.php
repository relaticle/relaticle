<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::queue('media:purge-unsafe-images', ['--force' => true])
            ->onQueue('imports')
            ->afterCommit();
    }
};
