<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a TipTap document JSON column to chat messages with the empty-doc
 * default in a single statement.
 *
 * In local/testing, also truncates the alpha chat data so the column can be
 * NOT NULL from day one. Production-safe: does NOT wipe data outside dev.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('local', 'testing')) {
            // Children first; CASCADE for any FK-referencing rows we missed.
            DB::statement('TRUNCATE TABLE agent_conversation_message_mentions, pending_actions, ai_credit_transactions, agent_conversation_messages, agent_conversations RESTART IDENTITY CASCADE');
        }

        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->jsonb('document')
                ->default(DB::raw("'{\"type\":\"doc\",\"content\":[]}'::jsonb"));
        });
    }
};
