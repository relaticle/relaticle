<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every statement below takes ACCESS EXCLUSIVE, and Laravel wraps the whole
        // migration in one transaction on PostgreSQL, so the lock is held from the
        // first DDL to commit. Without a timeout the first ALTER queues behind any
        // in-flight SELECT and every query arriving after it queues behind the ALTER,
        // turning a slow reader into a full outage. Fail fast instead and retry the
        // deploy in a quieter moment.
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->dropForeign('agent_conversations_user_id_foreign');
            $table->dropIndex('agent_conversations_user_id_updated_at_index');
            $table->dropIndex('agent_conversations_team_id_user_id_updated_at_index');
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->dropForeign('agent_conversation_messages_user_id_foreign');
            $table->dropIndex('agent_conversation_messages_user_id_index');
            $table->dropIndex('conversation_index');
        });

        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->renameColumn('user_id', 'participant_id');
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->renameColumn('user_id', 'participant_id');
        });

        $participantType = (new User)->getMorphClass();

        // Two things happen per table here, and the order matters for lock duration.
        //
        // participant_id was char(26) to fit a User ULID. A polymorphic key has no
        // fixed width, and char() blank-pads shorter keys on read, which would break
        // the strict participant comparisons in the chat authorization paths. bpchar
        // to varchar is not binary-coercible, so this rewrites the table — unavoidable.
        //
        // participant_type is added WITH a default so PostgreSQL 11+ records it in
        // pg_attribute.attmissingval and materialises it lazily. That backfills every
        // existing row in metadata-only time; the full-table UPDATE it replaces was a
        // second rewrite that doubled the relation size in dead tuples.
        Schema::table('agent_conversations', function (Blueprint $table) use ($participantType): void {
            $table->string('participant_id')->nullable()->change();
            $table->string('participant_type')->nullable()->default($participantType);
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table) use ($participantType): void {
            $table->string('participant_id')->nullable()->change();
            $table->string('participant_type')->nullable()->default($participantType);
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->text('approval_state')->nullable();
        });

        // The default was a backfill device, not the column's contract — every writer
        // supplies participant_type explicitly, and anonymised rows set it to null.
        // Dropping it is a catalog update, so it costs nothing.
        DB::statement('ALTER TABLE agent_conversations ALTER COLUMN participant_type DROP DEFAULT');
        DB::statement('ALTER TABLE agent_conversation_messages ALTER COLUMN participant_type DROP DEFAULT');

        // Rows the old nullOnDelete foreign key already emptied have no participant to
        // describe. This touches only that set, not the whole table.
        DB::table('agent_conversations')
            ->whereNull('participant_id')
            ->update(['participant_type' => null]);

        DB::table('agent_conversation_messages')
            ->whereNull('participant_id')
            ->update(['participant_type' => null]);

        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
            $table->index(['team_id', 'participant_type', 'participant_id', 'updated_at'], 'team_participant_updated_at_index');
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
            $table->index(['participant_type', 'participant_id'], 'participant_index');
        });
    }
};
