<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('activity_log', 'batch_uuid')) {
            return;
        }

        Schema::table('activity_log', function (Blueprint $table): void {
            // Stamped per request/job so a single save's native + custom-field rows
            // share a value; the timeline groups same-save rows on it. Host-owned —
            // the activity-log package only reads this column.
            $table->uuid('batch_uuid')->nullable()->index();
        });
    }
};
