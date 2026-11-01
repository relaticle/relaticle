<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const array INDEXES = [
        'emails_workspace_conversation_key_index' => 'btree (workspace_id, conversation_key)',
        'emails_workspace_rfc_message_id_index' => 'btree (workspace_id, rfc_message_id)',
        'emails_workspace_in_reply_to_index' => 'btree (workspace_id, in_reply_to)',
        'emails_references_ids_index' => 'gin (string_to_array("references", \' \'))',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => $definition) {
            $this->dropInvalidIndex($index);

            DB::statement("create index concurrently if not exists {$index} on emails using {$definition}");
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
