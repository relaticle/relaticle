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
        Schema::table('table_preferences', function (Blueprint $table): void {
            $table->jsonb('view_order')->nullable();
        });

        DB::table('table_preferences')
            ->whereNotNull('default_view_id')
            ->eachById(function (object $preference): void {
                DB::table('table_preferences')
                    ->where('id', $preference->id)
                    ->update(['view_order' => json_encode([$preference->default_view_id], JSON_THROW_ON_ERROR)]);
            });

        Schema::table('table_preferences', function (Blueprint $table): void {
            $table->dropForeign(['default_view_id']);
            $table->dropColumn('default_view_id');
        });
    }
};
