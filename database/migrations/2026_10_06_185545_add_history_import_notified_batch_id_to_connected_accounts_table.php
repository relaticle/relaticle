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
        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->string('history_import_notified_batch_id')->nullable();
        });

        DB::table('connected_accounts')
            ->whereNotNull('sync_cursor')
            ->whereNotNull('history_import_batch_id')
            ->update(['history_import_notified_batch_id' => DB::raw('history_import_batch_id')]);
    }
};
