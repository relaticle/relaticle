<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['people' => 'people', 'companies' => 'company'] as $table => $morphType) {
            DB::table($table)
                ->select('id')
                ->where('creation_source', 'system')
                ->where(fn (Builder $query): Builder => $query
                    ->whereExists(fn (Builder $link): Builder => $link->from('emailables')
                        ->where('emailable_type', $morphType)
                        ->whereColumn('emailable_id', "{$table}.id"))
                    ->orWhereExists(fn (Builder $link): Builder => $link->from('meetingables')
                        ->where('meetingable_type', $morphType)
                        ->whereColumn('meetingable_id', "{$table}.id")))
                ->eachById(function (object $record) use ($table, $morphType): void {
                    DB::table($table)->where('id', $record->id)->update(['creation_source' => 'mailbox']);

                    DB::table('activity_log')
                        ->where('subject_type', $morphType)
                        ->where('subject_id', $record->id)
                        ->whereNull('causer_id')
                        ->update(['properties' => DB::raw("jsonb_set(coalesce(properties::jsonb, '{}'::jsonb), '{source}', '\"mailbox\"')::json")]);
                });
        }
    }
};
