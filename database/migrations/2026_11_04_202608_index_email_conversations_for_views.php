<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const array INDEXES = [
        'email_conversations_workspace_last_message_id_idx' => '(workspace_id, last_message_at desc, id desc)',
        'email_conversations_needs_reply_idx' => '(workspace_id, last_message_at desc, id desc) where last_external_inbound_at is not null and (last_member_outbound_at is null or last_external_inbound_at > last_member_outbound_at) and not is_automated',
        'email_conversations_automated_idx' => '(workspace_id, last_message_at desc, id desc) where is_automated',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => $definition) {
            $this->dropInvalidIndex($index);

            DB::statement("create index concurrently if not exists {$index} on email_conversations {$definition}");
        }

        // A strict prefix of email_conversations_workspace_last_message_id_idx, kept only to slow summary upserts.
        DB::statement('drop index concurrently if exists email_conversations_workspace_id_last_message_at_index');
    }

    // A failed concurrent build leaves an invalid index behind, and `if not exists` would skip it on the retry.
    private function dropInvalidIndex(string $index): void
    {
        $isInvalid = DB::table('pg_index')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
            ->where('pg_class.relname', $index)
            ->whereRaw("pg_class.relnamespace = 'public'::regnamespace")
            ->where('pg_index.indisvalid', false)
            ->exists();

        if ($isInvalid) {
            DB::statement("drop index concurrently {$index}");
        }
    }
};
