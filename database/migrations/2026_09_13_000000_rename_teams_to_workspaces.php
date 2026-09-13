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
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('ai_credit_balances', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('ai_credit_transactions', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('chat_message_feedback', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('exports', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('failed_import_rows', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('imports', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('notes', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('oauth_access_tokens', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('pending_actions', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('people', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('team_user', function (Blueprint $table): void {
            $table->renameColumn('team_id', 'workspace_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('current_team_id', 'current_workspace_id');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->renameColumn('personal_team', 'personal_workspace');
        });

        Schema::rename('teams', 'workspaces');
        Schema::rename('team_user', 'workspace_user');
        Schema::rename('team_invitations', 'workspace_invitations');

        $this->renameCatalogObjects();

        DB::table('media')->where('model_type', 'team')->update(['model_type' => 'workspace']);
    }

    /**
     * Index, constraint and sequence names are read back from the catalog rather
     * than listed: production lost several named uniques and its pre-2026 foreign
     * keys, so a fixed list would fail there on the first missing object.
     * Constraints go first, because renaming a unique or primary key carries its
     * index along and the index sweep should only see what is left.
     */
    private function renameCatalogObjects(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            DECLARE r record;
            BEGIN
                FOR r IN
                    SELECT conrelid::regclass::text AS table_name, conname
                    FROM pg_constraint
                    WHERE connamespace = 'public'::regnamespace AND conname LIKE '%team%'
                LOOP
                    EXECUTE format(
                        'ALTER TABLE %s RENAME CONSTRAINT %I TO %I',
                        r.table_name, r.conname, replace(r.conname, 'team', 'workspace')
                    );
                END LOOP;

                FOR r IN
                    SELECT indexname FROM pg_indexes
                    WHERE schemaname = 'public' AND indexname LIKE '%team%'
                LOOP
                    EXECUTE format(
                        'ALTER INDEX public.%I RENAME TO %I',
                        r.indexname, replace(r.indexname, 'team', 'workspace')
                    );
                END LOOP;

                FOR r IN
                    SELECT sequence_name FROM information_schema.sequences
                    WHERE sequence_schema = 'public' AND sequence_name LIKE '%team%'
                LOOP
                    EXECUTE format(
                        'ALTER SEQUENCE public.%I RENAME TO %I',
                        r.sequence_name, replace(r.sequence_name, 'team', 'workspace')
                    );
                END LOOP;
            END $$;
        SQL);
    }
};
