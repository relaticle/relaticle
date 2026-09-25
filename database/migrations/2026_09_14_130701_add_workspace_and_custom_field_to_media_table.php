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
        Schema::table('media', function (Blueprint $table): void {
            $table->ulid('workspace_id')->nullable()->after('id')->index();
        });

        DB::table('media')
            ->whereNotNull('custom_properties->workspace_id')
            ->update(['workspace_id' => DB::raw("custom_properties->>'workspace_id'")]);
    }
};
