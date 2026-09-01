<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->unsignedInteger('initial_sync_imported')->default(0)->after('last_synced_at');
            $table->unsignedInteger('initial_sync_estimated')->nullable()->after('initial_sync_imported');
        });
    }
};
