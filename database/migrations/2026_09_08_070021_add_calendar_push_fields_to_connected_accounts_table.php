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
            $table->string('calendar_push_channel_id')->nullable()->after('last_calendar_synced_at');
            $table->string('calendar_push_resource_id')->nullable()->after('calendar_push_channel_id');
            $table->text('calendar_push_verification_token')->nullable()->after('calendar_push_resource_id');
            $table->timestamp('calendar_push_expires_at')->nullable()->after('calendar_push_verification_token');
        });
    }
};
