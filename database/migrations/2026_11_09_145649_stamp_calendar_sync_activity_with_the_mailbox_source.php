<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('activity_log')
            ->select('id')
            ->where('subject_type', 'meeting')
            ->whereRaw("properties::jsonb ->> 'source' = 'web'")
            ->chunkById(1000, function (iterable $activities): void {
                DB::table('activity_log')
                    ->whereIn('id', collect($activities)->pluck('id'))
                    ->update(['properties' => DB::raw("jsonb_set(properties::jsonb, '{source}', '\"mailbox\"')::json")]);
            });
    }
};
