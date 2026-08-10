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
            $table->string('calendar_sync_cursor')->nullable()->after('sync_cursor');
            $table->timestamp('last_calendar_synced_at')->nullable()->after('last_synced_at');
        });
    }
};
