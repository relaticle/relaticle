<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::queue('custom-fields:normalize-values', ['--force' => true])
            ->onQueue('imports')
            ->afterCommit();
    }
};
