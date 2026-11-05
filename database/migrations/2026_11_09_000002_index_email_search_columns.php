<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const array INDEXES = [
        'emails_workspace_subject_search_idx' => 'emails using gin (workspace_id, subject_search)',
        'emails_workspace_body_search_idx' => 'emails using gin (workspace_id, body_search)',
        'email_participants_email_address_trgm_idx' => 'email_participants using gin (email_address gin_trgm_ops)',
        'email_participants_name_trgm_idx' => 'email_participants using gin (name gin_trgm_ops)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => $definition) {
            $this->dropInvalidIndex($index);

            DB::statement("create index concurrently if not exists {$index} on {$definition}");
        }
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
