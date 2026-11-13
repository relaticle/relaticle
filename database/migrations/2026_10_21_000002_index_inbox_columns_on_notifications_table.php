<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const array INDEXES = [
        'notifications_workspace_id_index' => 'create index concurrently if not exists notifications_workspace_id_index on notifications (workspace_id)',
        'notifications_actor_id_index' => 'create index concurrently if not exists notifications_actor_id_index on notifications (actor_id)',
        'notifications_subject_type_subject_id_index' => 'create index concurrently if not exists notifications_subject_type_subject_id_index on notifications (subject_type, subject_id)',
        'notifications_list_idx' => 'create index concurrently if not exists notifications_list_idx on notifications (notifiable_id, workspace_id, archived_at, created_at)',
        'notifications_unread_idx' => 'create index concurrently if not exists notifications_unread_idx on notifications (notifiable_id, workspace_id) where read_at is null and archived_at is null',
        'notifications_group_key_unique' => 'create unique index concurrently if not exists notifications_group_key_unique on notifications (notifiable_id, group_key) where group_key is not null and read_at is null and archived_at is null',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $statement) {
            $this->dropInvalidIndex($name);

            DB::statement($statement);
        }
    }

    // A failed concurrent build leaves an invalid index behind, and `if not exists` would skip it on the retry.
    private function dropInvalidIndex(string $index): void
    {
        $isInvalid = DB::table('pg_index')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
            ->where('pg_class.relname', $index)
            ->where('pg_index.indisvalid', false)
            ->exists();

        if ($isInvalid) {
            DB::statement("drop index concurrently {$index}");
        }
    }
};
