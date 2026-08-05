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

        // The column was char(26) to fit a User ULID. A polymorphic key has no fixed
        // width, and char() blank-pads shorter keys on read, which would break the
        // strict participant comparisons in the chat authorization paths.
        Schema::table('agent_conversations', function (Blueprint $table): void {
            $table->string('participant_id')->nullable()->change();
            $table->string('participant_type')->nullable();
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->string('participant_id')->nullable()->change();
            $table->string('participant_type')->nullable();
            $table->text('approval_state')->nullable();
        });

        $participantType = (new User)->getMorphClass();

        DB::table('agent_conversations')
            ->whereNotNull('participant_id')
            ->update(['participant_type' => $participantType]);

        DB::table('agent_conversation_messages')
            ->whereNotNull('participant_id')
            ->update(['participant_type' => $participantType]);

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
