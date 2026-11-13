<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const array ENTITY_TYPES = ['company', 'people', 'opportunity', 'task', 'note'];

    public function up(): void
    {
        Cache::forget('custom_fields_entities');

        DB::table('workspaces')->select('id')->eachById(function (object $workspace): void {
            foreach (self::ENTITY_TYPES as $entityType) {
                Cache::forget("custom_fields_schema_{$workspace->id}_{$entityType}");
                Cache::forget("custom_fields_filter_schema_{$workspace->id}_{$entityType}");
            }
        });
    }
};
