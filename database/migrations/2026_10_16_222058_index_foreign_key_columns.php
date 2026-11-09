<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const array COLUMNS = [
        'ai_credit_transactions' => ['user_id'],
        'ai_summaries' => ['workspace_id'],
        'blog_posts' => ['author_id', 'category_id'],
        'chat_message_feedback' => ['conversation_id', 'message_id'],
        'companies' => ['account_owner_id', 'creator_id'],
        'email_access_requests' => ['requester_id'],
        'email_attachments' => ['email_id'],
        'email_batches' => ['connected_account_id'],
        'email_blocklists' => ['user_id', 'workspace_id'],
        'email_participants' => ['company_id'],
        'email_shares' => ['shared_by'],
        'email_signatures' => ['connected_account_id', 'user_id'],
        'email_templates' => ['created_by', 'workspace_id'],
        'exports' => ['user_id', 'workspace_id'],
        'failed_import_rows' => ['import_id', 'workspace_id'],
        'imports' => ['user_id', 'workspace_id'],
        'meeting_attendees' => ['company_id', 'contact_id'],
        'notes' => ['creator_id'],
        'oauth_refresh_tokens' => ['access_token_id'],
        'opportunities' => ['company_id', 'contact_id', 'creator_id'],
        'pending_actions' => ['user_id'],
        'people' => ['company_id', 'creator_id'],
        'protected_recipients' => ['created_by'],
        'tasks' => ['creator_id'],
        'user_social_accounts' => ['user_id'],
        'workspace_email_blocklists' => ['created_by'],
        'workspace_invitations' => ['inviter_id'],
        'workspace_user' => ['user_id'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $index = "{$table}_{$column}_index";

                $this->dropInvalidIndex($index);

                DB::statement("create index concurrently if not exists {$index} on {$table} ({$column})");
            }
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
